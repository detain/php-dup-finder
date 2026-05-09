# CLI reference

```
Usage:
  phpdup analyze <paths...> [options]
```

## Arguments

| Argument | Description                               |
|----------|-------------------------------------------|
| `paths`  | One or more directories or files to scan. |

## Options

| Option                       | Default       | Description                                              |
|------------------------------|---------------|----------------------------------------------------------|
| `-c, --config FILE`          | —             | Load settings from a `phpdup.json` config file.          |
| `--min-block-size N`         | `8`           | Minimum AST node count for a block to be considered.    |
| `--mode MODE`                | `aggressive`  | Normalization mode: `strict`, `default`, `aggressive`.   |
| `--similarity N`             | `0.80`        | Jaccard similarity threshold for near-duplicate pairs.   |
| `--min-impact N`             | `20`          | Minimum cluster impact score to include in the report.   |
| `--html DIR`                 | —             | Write the HTML report to this directory.                 |
| `--json FILE`                | —             | Write the JSON report to this file.                      |
| `--exact-only`               | off           | Skip the near-duplicate phase. Faster.                   |
| `--limit N`                  | `50`          | Maximum number of clusters to print to the terminal.     |
| `--stats`                    | off           | Print pipeline stage timings and block-kind histogram.   |
| `--no-cache`                 | off           | Don't read or write the AST cache for this run.          |
| `-h, --help`                 |               | Show help.                                               |

## Exit codes

| Code | Meaning                                              |
|------|------------------------------------------------------|
| `0`  | Analysis ran. Note: phpdup does NOT exit non-zero    |
|      | when clusters are found. Use the JSON report to gate |
|      | CI; an empty `clusters` array means clean.           |
| `2`  | Missing required argument.                           |
| `1`  | Internal error.                                      |

## Examples

Default analysis of one directory:

```bash
bin/phpdup analyze src
```

Multiple directories with both reports:

```bash
bin/phpdup analyze src lib --html report --json report.json
```

Loose threshold to find more candidates:

```bash
bin/phpdup analyze src --similarity 0.7 --min-impact 10
```

CI-style fast pass for exact clones only:

```bash
bin/phpdup analyze src --exact-only --min-impact 30 --json clones.json
test "$(jq '.clusters | length' clones.json)" -eq 0
```

Performance diagnostic:

```bash
bin/phpdup analyze src --stats --no-cache
```

## Configuration file

```json
{
  "paths":   ["src", "app"],
  "exclude": ["vendor/**", "node_modules/**", "**/*.tpl.php"],
  "min_block_size":       8,
  "max_block_size":       800,
  "normalization_mode":   "aggressive",
  "similarity_threshold": 0.80,
  "tree_threshold":       0.85,
  "min_cluster_impact":   20,
  "max_df":               0.01,
  "ngram_size":           5,
  "cache_dir":            ".phpdup-cache",
  "report": {
    "html": "phpdup-report",
    "json": "phpdup.json"
  }
}
```

CLI flags override config values.
