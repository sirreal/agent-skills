# PHP Tools LSP Plugin

A Claude Code plugin that configures the DEVSENSE PHP Language Server for enhanced PHP development capabilities including code completion, go-to-definition, diagnostics, and more.

## Installation

```sh
claude plugin marketplace add sirreal/agent-skills
claude plugin install phptools-lsp@sirreal
```

**Important**: Disable the official PHP LSP plugin to avoid conflicts:

```sh
claude plugin disable php-lsp@claude-plugins-official
```

## Prerequisites

- **PHP Tools License**: Requires a valid DEVSENSE PHP Tools license key
- **Language Server Binary**: Install `devsense-php-ls` from [DEVSENSE PHP Tools](https://www.devsense.com/en/download)

## Configuration

Add your PHP Tools license key to `~/.claude/settings.json`:

```json
{
  "env": {
    "DEVSENSE_PHP_LS_LICENSE": "YOUR KEY HERE"
  }
}
```

**Where to get a license:**
- Free trial: Available at [devsense.com](https://www.devsense.com)
- Purchase: Check DEVSENSE pricing for individual or team licenses

## Features

Once configured, the language server provides:

- **IntelliSense**: Context-aware code completion
- **Go to Definition**: Navigate to symbol definitions
- **Find References**: Locate all usages of symbols
- **Diagnostics**: Real-time error and warning detection
- **Hover Information**: View documentation on hover
- **Code Actions**: Quick fixes and refactoring suggestions

## Troubleshooting

**Language server not starting?**
- Verify `devsense-php-ls` is in your PATH: `which devsense-php-ls`
- Check that `DEVSENSE_PHP_LS_LICENSE` is set correctly in settings.json
- Verify your license key is valid and not expired

**No completions appearing?**
- Ensure PHP files use the `.php` extension
- Check Claude Code logs for LSP connection errors
