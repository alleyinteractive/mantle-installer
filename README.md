# Mantle Installer

Documentation can be found inside the [Mantle documentation](https://mantle.alley.co/).

[![Testing Suite](https://github.com/alleyinteractive/mantle-installer/actions/workflows/tests.yml/badge.svg)](https://github.com/alleyinteractive/mantle-installer/actions/workflows/tests.yml)

## Non-interactive usage

The installer never prompts when it is run with `--no-interaction` (`-n`) or when STDIN is not a terminal, which makes it safe to drive from scripts, CI, and LLM agents. Each prompt falls back to its default answer, and every choice can be passed as an argument or option instead:

```bash
# Install WordPress and Mantle into ./my-site, with the plugin at wp-content/plugins/my-site.
mantle new my-site --install --no-interaction

# Install Mantle into an existing WordPress installation.
mantle new my-plugin --wordpress-path=/path/to/wordpress --no-interaction
```

Run `mantle new --help` for every available option.
