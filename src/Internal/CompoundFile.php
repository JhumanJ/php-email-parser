<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Internal;

use JhumanJ\EmailParser\ParseOptions;
use JhumanJ\EmailParser\Exception\InvalidEmailException;

/** Original bounded reader of MS-CFB version 3/4, including mini streams. @internal */
final class CompoundFile
{
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const FREE = 0xFFFFFFFF;
    private const END = 0xFFFFFFFE;
    private int $sectorSize;
    private int $sectorCount;
    /** @var array<int,int> */
    private array $fat = [];
    /** @var array<int,int> */
    private array $miniFat = [];
    /** @var array<int,array{name:string,type:int,left:int,right:int,child:int,start:int,size:int}> */
    private array $entries = [];
    /** @var array<int,array<string,int>> */
    private array $children = [];
    private string $miniStream;
    public function __construct(private readonly string $bytes, ParseOptions $options)
    {
        if (strlen($bytes) < 512 || substr($bytes, 0, 8) !== self::SIGNATURE) {
            $this->invalid('Invalid CFB header.');
        }
        $major = self::u16($bytes, 26);
        $shift = self::u16($bytes, 30);
        if (self::u16($bytes, 28) !== 0xFFFE || !in_array([$major,$shift], [[3,9],[4,12]], true)
            || self::u16($bytes, 32) !== 6 || self::u32($bytes, 56) !== 4096) {
            $this->invalid('Unsupported CFB layout.');
        }
        $this->sectorSize = 1 << $shift;
        if (strlen($bytes) % $this->sectorSize !== 0) {
            $this->invalid('Truncated CFB sector.');
        }
        $this->sectorCount = intdiv(strlen($bytes), $this->sectorSize) - 1;
        $fatCount = self::u32($bytes, 44);
        if ($fatCount < 1 || $fatCount > $this->sectorCount) {
            $this->invalid('Invalid FAT count.');
        }
        $ids = [];
        for ($i = 0; $i < 109; ++$i) {
            $sid = self::u32($bytes, 76 + 4 * $i);
            if ($sid !== self::FREE) {
                $ids[] = $sid;
            }
        }
        $difat = self::u32($bytes, 68);
        $seen = [];
        $difatCount = self::u32($bytes, 72);
        if ($difatCount > $this->sectorCount) {
            $this->invalid('Invalid DIFAT count.');
        }
        for ($i = 0; $i < $difatCount; ++$i) {
            if (isset($seen[$difat])) {
                $this->invalid('DIFAT cycle.');
            }
            $seen[$difat] = true;
            $sector = $this->sector($difat);
            for ($j = 0; $j < $this->sectorSize - 4; $j += 4) {
                $sid = self::u32($sector, $j);
                if ($sid !== self::FREE) {
                    $ids[] = $sid;
                }
            }
            $difat = self::u32($sector, $this->sectorSize - 4);
        }
        if ($difatCount > 0 && $difat !== self::END) {
            $this->invalid('Invalid DIFAT termination.');
        }
        if (count($ids) !== $fatCount || count(array_unique($ids)) !== $fatCount) {
            $this->invalid('Invalid FAT sectors.');
        }
        foreach ($ids as $id) {
            array_push($this->fat, ...array_values(unpack('V*', $this->sector($id)) ?: []));
        }
        $directory = $this->chain(self::u32($bytes, 48), $this->fat, false);
        $count = intdiv(strlen($directory), 128);
        ParseContext::limit($count, $options->maxDirectoryEntries, 'CFB directory entries');
        for ($i = 0; $i < $count; ++$i) {
            $record = substr($directory, $i * 128, 128);
            $type = ord($record[66]);
            if ($type === 0) {
                continue;
            }
            $nameLength = self::u16($record, 64);
            if (!in_array($type, [1,2,5], true) || $nameLength < 2 || $nameLength > 64 || $nameLength % 2 !== 0) {
                $this->invalid('Invalid directory entry.');
            }
            $size = self::u32($record, 120);
            if ($major === 4) {
                $high = self::u32($record, 124);
                if ($high > 0) {
                    $this->invalid('Oversized CFB stream.');
                }
            }
            $this->entries[$i] = ['name' => mb_convert_encoding(substr($record, 0, $nameLength - 2), 'UTF-8', 'UTF-16LE'),'type' => $type,
                'left' => self::u32($record, 68),'right' => self::u32($record, 72),'child' => self::u32($record, 76),'start' => self::u32($record, 116),'size' => $size];
        }
        if (($this->entries[0]['type'] ?? null) !== 5) {
            $this->invalid('Missing CFB root.');
        }
        $root = $this->entries[0];
        $this->miniStream = $this->readRegular($root['start'], $root['size']);
        $miniCount = self::u32($bytes, 64);
        if ($miniCount > $this->sectorCount) {
            $this->invalid('Invalid mini FAT count.');
        }
        if ($miniCount > 0) {
            $miniBytes = $this->readRegular(self::u32($bytes, 60), $miniCount * $this->sectorSize);
            $this->miniFat = array_values(unpack('V*', $miniBytes) ?: []);
        }
        $visited = [0 => true];
        $pending = [0];
        while ($pending !== []) {
            $parent = array_pop($pending);
            $nodes = [$this->entries[$parent]['child']];
            $this->children[$parent] = [];
            while ($nodes !== []) {
                $id = array_pop($nodes);
                if ($id === self::FREE) {
                    continue;
                }
                if (!isset($this->entries[$id]) || isset($visited[$id])) {
                    $this->invalid('Invalid or cyclic directory tree.');
                }
                $visited[$id] = true;
                $entry = $this->entries[$id];
                if (isset($this->children[$parent][$entry['name']])) {
                    $this->invalid('Duplicate directory name.');
                }
                $this->children[$parent][$entry['name']] = $id;
                $nodes[] = $entry['left'];
                $nodes[] = $entry['right'];
                if ($entry['type'] === 1) {
                    $pending[] = $id;
                } elseif ($entry['type'] !== 2) {
                    $this->invalid('Unexpected root in directory tree.');
                }
            }
        }
    }
    /** @return array<string,int> */
    public function children(int $storage = 0): array
    {
        return $this->children[$storage] ?? [];
    }
    public function isStorage(int $id): bool
    {
        return ($this->entries[$id]['type'] ?? 0) === 1;
    }
    public function stream(int $id, ?int $limit = null): string
    {
        $e = $this->entries[$id] ?? null;
        if ($e === null || $e['type'] !== 2) {
            $this->invalid('Not a CFB stream.');
        }
        if ($limit !== null) {
            ParseContext::limit($e['size'], $limit, 'CFB stream bytes');
        }
        if ($e['size'] === 0) {
            return '';
        }
        if ($e['size'] >= 4096) {
            return $this->readRegular($e['start'], $e['size']);
        }
        return $this->chain($e['start'], $this->miniFat, true, $e['size']);
    }
    private function readRegular(int $start, int $size): string
    {
        if ($size === 0) {
            return '';
        }
        if ($size > strlen($this->bytes)) {
            $this->invalid('Invalid stream size.');
        }
        return $this->chain($start, $this->fat, false, $size);
    }
    /** @param array<int,int> $table */
    private function chain(int $start, array $table, bool $mini, ?int $size = null): string
    {
        $result = '';
        $seen = [];
        $unit = $mini ? 64 : $this->sectorSize;
        for ($sid = $start; $sid !== self::END; $sid = $table[$sid]) {
            if (!isset($table[$sid]) || isset($seen[$sid]) || count($seen) > $this->sectorCount * ($mini ? intdiv($this->sectorSize, 64) : 1)) {
                $this->invalid('Invalid or cyclic sector chain.');
            }
            $seen[$sid] = true;
            if ($size !== null && count($seen) > max(1, (int)ceil($size / $unit))) {
                $this->invalid('Stream chain exceeds declared size.');
            }
            if ($mini) {
                if (($sid + 1) * 64 > strlen($this->miniStream)) {
                    $this->invalid('Mini sector outside root stream.');
                }
                $result .= substr($this->miniStream, $sid * 64, 64);
            } else {
                $result .= $this->sector($sid);
            }
        }
        if ($size !== null && strlen($result) < $size) {
            $this->invalid('Incomplete stream chain.');
        }
        return $size === null ? $result : substr($result, 0, $size);
    }
    private function sector(int $id): string
    {
        if ($id < 0 || $id >= $this->sectorCount) {
            $this->invalid('Sector outside file.');
        }
        return substr($this->bytes, ($id + 1) * $this->sectorSize, $this->sectorSize);
    }
    public static function u16(string $bytes, int $offset): int
    {
        return unpack('v', substr($bytes, $offset, 2))[1];
    }
    public static function u32(string $bytes, int $offset): int
    {
        return unpack('V', substr($bytes, $offset, 4))[1];
    }
    private function invalid(string $message): never
    {
        throw new InvalidEmailException($message);
    }
}
