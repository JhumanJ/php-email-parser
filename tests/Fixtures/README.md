# Test data provenance

All root fixtures and the deterministic fixture generator in `tests/Support` are original work for this project, licensed under the project's MIT license. Addresses and attachment bytes are synthetic. The generator uses only Python's standard library and writes CFB/MSG according to Microsoft's public specifications; Python is not a runtime dependency.

`upstream-mapi/sample.msg` is copied verbatim from [hfig/MAPI](https://github.com/hfig/MAPI/blob/5367b051ca6e57c7300865f6cd2d657d57155d93/tests/_files/sample.msg), commit `5367b051ca6e57c7300865f6cd2d657d57155d93`. The corresponding regression assertions are adapted from its `MapiMessageFactoryTest.php`. Its MIT license and copyright notice are preserved in `upstream-mapi/LICENSE`. This is an upstream public sample, not a customer email.

No code, tests or fixtures from the GPL-licensed `extract-msg` project are included. Its documented behavior informed the feature scope; the MSG and RTF implementations are original implementations based on Microsoft specifications.
