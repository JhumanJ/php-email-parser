#!/usr/bin/env python3
"""Original deterministic CFB/MSG fixture writer. Python stdlib only; not a parser."""
from pathlib import Path
from struct import pack, unpack_from
import base64
import binascii

OUT = Path(__file__).resolve().parents[1] / 'Fixtures'
FREE, END, FAT = 0xffffffff, 0xfffffffe, 0xfffffffd
HEADERS = ('From: =?UTF-8?B?w4lxdWlwZSBmYWN0dXJhdGlvbg==?= <billing@example.com>\r\n'
           'To: operations@example.net\r\nCc: audit@example.net\r\nBcc: private@example.net\r\n'
           'Reply-To: support@example.com\r\nSubject: =?UTF-8?B?RmFjdHVyZSDDqXTDqQ==?=\r\n'
           'Date: Thu, 01 Oct 2026 10:30:00 +0200\r\nMessage-ID: <fixture@example.com>\r\n')
PDF = b'%PDF-1.4\nfixture\n'
PNG = b'\x89PNG\r\n\x1a\nfixture'


def cfb(tree, version=3):
    size = 512 if version == 3 else 4096
    entries = [dict(name='Root Entry', typ=5, left=FREE, right=FREE, child=FREE, data=b'')]
    def add_children(parent, values):
        ids = []
        for name, value in sorted(values.items(), key=lambda item: (len(item[0]), item[0].upper())):
            idx = len(entries); ids.append(idx)
            entries.append(dict(name=name, typ=1 if isinstance(value, dict) else 2,
                                left=FREE, right=FREE, child=FREE, data=b'' if isinstance(value, dict) else value))
            if isinstance(value, dict): add_children(idx, value)
        def branch(nodes):
            if not nodes: return FREE
            m = len(nodes)//2; idx = nodes[m]
            entries[idx]['left'] = branch(nodes[:m]); entries[idx]['right'] = branch(nodes[m+1:])
            return idx
        entries[parent]['child'] = branch(ids)
    add_children(0, tree)
    sectors, chains, mini, minifat = [], [], bytearray(), []
    def allocate(data):
        if not data: return END
        first = len(sectors); count = (len(data)+size-1)//size
        for i in range(count): sectors.append(data[i*size:(i+1)*size].ljust(size, b'\0'))
        chains.append((first, count)); return first
    for entry in entries[1:]:
        data = entry['data']; entry['length'] = len(data)
        if entry['typ'] != 2 or not data: entry['start'] = END
        elif len(data) < 4096:
            first = len(minifat); count = (len(data)+63)//64
            entry['start'] = first
            mini.extend(data.ljust(count*64, b'\0'))
            minifat.extend([first+i+1 if i<count-1 else END for i in range(count)])
        else: entry['start'] = allocate(data)
    entries[0]['start'] = allocate(bytes(mini)); entries[0]['length'] = len(mini)
    minifirst = allocate(b''.join(pack('<I', n) for n in minifat))
    minicount = (len(minifat)*4+size-1)//size
    directory = bytearray()
    for e in entries:
        name = (e['name']+'\0').encode('utf-16le')
        record = bytearray(128); record[:len(name)] = name
        record[64:68] = pack('<HBB', len(name), e['typ'], 1)
        record[68:80] = pack('<III', e['left'], e['right'], e['child'])
        record[116:128] = pack('<IQ', e.get('start', END), e.get('length', 0))
        directory.extend(record)
    dirfirst = allocate(directory)
    fatcount = 1
    while fatcount*size//4 < len(sectors)+fatcount: fatcount += 1
    fatfirst = len(sectors)
    table = [FREE]*(fatcount*size//4)
    for first, count in chains:
        for i in range(count): table[first+i] = first+i+1 if i<count-1 else END
    for i in range(fatcount): table[fatfirst+i] = FAT
    fatbytes = b''.join(pack('<I', n) for n in table)
    for i in range(fatcount): sectors.append(fatbytes[i*size:(i+1)*size])
    header = bytearray(size)
    header[:8] = bytes.fromhex('d0cf11e0a1b11ae1')
    header[24:34] = pack('<HHHHH', 0x3e, version, 0xfffe, 9 if version==3 else 12, 6)
    header[40:76] = pack('<IIIIIIIII', 0 if version==3 else len(directory)//size+bool(len(directory)%size),
                         fatcount, dirfirst, 0, 4096, minifirst, minicount, END, 0)
    header[76:512] = b''.join(pack('<I', fatfirst+i if i<fatcount else FREE) for i in range(109))
    return bytes(header)+b''.join(sectors)


def properties(strings=None, binary=None, fixed=None, context='root', ansi=False):
    strings, binary, fixed = strings or {}, binary or {}, fixed or {}
    tree = {}; records = bytearray()
    for prop, text in strings.items():
        typ = 0x001e if ansi else 0x001f
        data = (text+'\0').encode('cp1252' if ansi else 'utf-16le')
        tree[f'__substg1.0_{prop:04X}{typ:04X}'] = data
        records.extend(pack('<IIII', prop<<16|typ, 6, len(data), 0))
    for prop, data in binary.items():
        tree[f'__substg1.0_{prop:04X}0102'] = data
        records.extend(pack('<IIII', prop<<16|0x0102, 6, len(data), 0))
    for prop, (typ, value) in fixed.items():
        records.extend(pack('<IIQ', prop<<16|typ, 6, value))
    tree['__properties_version1.0'] = bytes({'root':32,'embedded':24,'other':8}[context])+records
    return tree


def rtf(raw, compressed=False):
    if not compressed: return pack('<IIII', len(raw)+12, len(raw), 0x414c454d, 0)+raw
    payload = b''.join(b'\0'+raw[i:i+8] for i in range(0,len(raw),8))
    # MS-OXRTFCP CRC uses initial zero, no final XOR (unlike standard CRC-32).
    crc = 0
    for byte in payload:
        crc ^= byte
        for _ in range(8): crc = (crc>>1) ^ (0xedb88320 if crc&1 else 0)
    return pack('<IIII',len(payload)+12,len(raw),0x75465a4c,crc)+payload


def basic(context='root', ansi=False, bodies=True):
    strings = {0x0037:'Facture été',0x0c1a:'Équipe facturation',0x0c1f:'billing@example.com',
               0x0c1e:'SMTP',0x5d01:'billing@example.com',0x007d:HEADERS,0x1035:'<fixture@example.com>'}
    if bodies: strings[0x1000] = 'Bonjour été €' if ansi else 'Bonjour été'
    tree = properties(strings, {0x1013:'<html><p>Bonjour été</p></html>'.encode()} if bodies else {},
                      {0x3ffd:(3,1252 if ansi else 65001)},context,ansi)
    for i,(email,typ) in enumerate([('operations@example.net',1),('audit@example.net',2),('private@example.net',3)]):
        tree[f'__recip_version1.0_#{i:08X}'] = properties({0x3001:email,0x3003:email,0x3002:'SMTP'},fixed={0x0c15:(3,typ)},context='other',ansi=ansi)
    for i,(name,mime,data,cid) in enumerate([('facture.pdf','application/pdf',PDF,''),('logo.png','image/png',PNG,'logo@example.com')]):
        s={0x3707:name,0x370e:mime}
        if cid: s[0x3712]=cid
        tree[f'__attach_version1.0_#{i:08X}'] = properties(s,{0x3701:data},{0x3705:(3,1),0x7ffe:(11,bool(cid))},'other',ansi)
    return tree


def main():
    OUT.mkdir(exist_ok=True)
    eml = HEADERS+'MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary="mix"\r\n\r\n'
    eml += '--mix\r\nContent-Type: multipart/alternative; boundary="alt"\r\n\r\n'
    eml += '--alt\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n'+base64.b64encode('Bonjour été'.encode()).decode()+'\r\n'
    eml += '--alt\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n'+base64.b64encode('<html><p>Bonjour été</p></html>'.encode()).decode()+'\r\n--alt--\r\n'
    for name,mime,data,cid in [('facture.pdf','application/pdf',PDF,''),('logo.png','image/png',PNG,'logo@example.com')]:
        eml += f'--mix\r\nContent-Type: {mime}\r\nContent-Disposition: '+('inline' if cid else 'attachment')+f'; filename="{name}"\r\n'
        if cid: eml += f'Content-ID: <{cid}>\r\n'
        eml += 'Content-Transfer-Encoding: base64\r\n\r\n'+base64.b64encode(data).decode()+'\r\n'
    eml += '--mix--\r\n'
    (OUT/'basic.eml').write_bytes(eml.encode())
    (OUT/'nested.eml').write_bytes(('From: outer@example.com\r\nSubject: Forward\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary="outer"\r\n\r\n--outer\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment; filename="forwarded.eml"\r\nContent-Transfer-Encoding: base64\r\n\r\n'+base64.b64encode(eml.encode()).decode()+'\r\n--outer--\r\n').encode())
    (OUT/'basic.msg').write_bytes(cfb(basic()))
    (OUT/'basic-v4.msg').write_bytes(cfb(basic(),4))
    (OUT/'ansi.msg').write_bytes(cfb(basic(ansi=True)))
    (OUT/'no-body.msg').write_bytes(cfb(basic(bodies=False)))
    for filename,raw,compressed in [('rtf-only.msg',br"{\rtf1\ansi\ansicpg1252{\fonttbl{\f0 Hidden font;}}Bonjour \'e9t\'e9 \u8364?\par Deuxi\'e8me ligne}",True),('rtf-html.msg',br'{\rtf1\ansi\fromhtml1{\*\htmltag1 <html><p>Bonjour \u233?t\u233?</p></html>}}',False)]:
        tree=properties({0x0037:'RTF fixture'}, {0x1009:rtf(raw,compressed)})
        (OUT/filename).write_bytes(cfb(tree))
        if compressed:
            bad=bytearray(rtf(raw,compressed)); bad[12]^=1
            (OUT/'bad-rtf-crc.msg').write_bytes(cfb(properties({0x0037:'Invalid CRC'},{0x1009:bytes(bad)})))
    nested=properties({0x0037:'Forward',0x1000:'See attached.'})
    attach=properties({0x3707:'forwarded.msg'},fixed={0x3705:(3,5)},context='other')
    attach['__substg1.0_3701000D']=basic(context='embedded')
    nested['__attach_version1.0_#00000000']=attach
    (OUT/'nested.msg').write_bytes(cfb(nested))
    unsupported=basic(); unsupported['__attach_version1.0_#00000000']=properties({0x3707:'external.doc'},{},{0x3705:(3,2)},'other')
    (OUT/'unsupported-attachment.msg').write_bytes(cfb(unsupported))
    data=(OUT/'basic.msg').read_bytes(); (OUT/'truncated.msg').write_bytes(data[:100])
    fatid=unpack_from('<I',data,76)[0]; dirid=unpack_from('<I',data,48)[0]; miniid=unpack_from('<I',data,60)[0]
    broken=bytearray(data); broken[(fatid+1)*512+dirid*4:(fatid+1)*512+dirid*4+4]=pack('<I',dirid)
    (OUT/'fat-cycle.msg').write_bytes(broken)
    broken=bytearray(data); broken[(miniid+1)*512:(miniid+1)*512+4]=pack('<I',0)
    (OUT/'mini-fat-cycle.msg').write_bytes(broken)
    broken=bytearray(data); offset=(dirid+1)*512+76; broken[offset:offset+4]=pack('<I',0)
    (OUT/'directory-cycle.msg').write_bytes(broken)
    (OUT/'empty.eml').write_bytes(b''); (OUT/'garbage.eml').write_bytes(b'not an email')

if __name__=='__main__': main()
