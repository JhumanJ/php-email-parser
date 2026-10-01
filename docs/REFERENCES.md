# Implementation references

The MSG implementation follows the following Microsoft protocol specifications:

- [MS-CFB: Compound File Binary File Format](https://learn.microsoft.com/en-us/openspecs/windows_protocols/ms-cfb/05060311-bfce-4b12-874d-71fd4ce63aea): header, FAT/DIFAT, mini FAT, directory trees and stream sizes.
- [MS-OXMSG: Outlook Item (.msg) File Format](https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-oxmsg/f75700c6-b10e-4510-bd3e-079ed3d3592f): property streams, recipients, attachments and embedded message storage.
- [MS-OXRTFCP: RTF Compression Algorithm](https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-oxrtfcp/65dfe2df-1b69-43fc-8ebd-21819a7463fb): LZFu/MELA headers, CRC, circular dictionary and references.
- [MS-OXRTFCP initial dictionary](https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-oxrtfcp/4238b0e2-7147-42da-88c9-ea45a1243e67): the mandated 207-byte constant, including CR/LF at positions 168–169.

During development, outputs were compared against extract-msg 0.56.1 on its public examples and hfig/MAPI's public samples. This was an external behavioral check, not a runtime dependency or copied implementation. The checked-in corpus is listed with provenance in `tests/Fixtures/README.md`.
