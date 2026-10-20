# Contributing

We welcome focused improvements to NexPilot Community. Open an issue before proposing a new capability or protocol change so its privacy and compatibility impact can be reviewed.

## Development

1. Work against a disposable WordPress installation with synthetic content.
2. Run `php tests/protocol.php`, `node --test tests/bridge.test.mjs`, and `python3 build.py`.
   For integration changes, also run `python3 tests/integration-docker.py` with Docker available.
3. Describe the behavior, test evidence, and compatibility limits in the pull request.
4. Keep commits small and use a professional Git identity that you intend to publish.

Never include client credentials, live site URLs, customer information, database exports, private logs, screenshots of authorization flows, or commercial plugin archives. Report suspected security issues privately using [SECURITY.md](SECURITY.md).

Submissions are expected to be original work or include the required compatible license notices. By submitting a contribution, you agree that it is distributed under this repository's GPL-2.0-or-later license.
