# Security

Please report parser crashes, unbounded resource usage or unsafe output handling through a private [GitHub vulnerability report](https://github.com/JhumanJ/email-parser/security/advisories/new) when enabled. Otherwise open an issue requesting a private reporting channel without including exploit details or confidential email contents.

The current 0.1.x series receives fixes. Use resource limits, application worker budgets and HTML sanitation when parsing untrusted mail. Parsing does not authenticate messages or make attachments safe to execute.
