# Contributing

Thanks for looking. phpvin is small on purpose, so the most useful thing you can
do is help keep it that way.

## Getting set up

```bash
git clone https://github.com/innovin/phpvin.git
cd phpvin
make install
make test
```

`make install` installs both packages: the framework at the repository root and
the skeleton application in `skeleton/`, which resolves the framework through a
Composer path repository so your edits to `src/` are live in the running app.

```bash
make serve     # the skeleton at http://localhost:8000
make migrate   # apply pending migrations
```

## Before you open a pull request

```bash
composer check
```

That runs the three things CI runs: code style, PHPStan at level 6, and the test
suite. All three must pass. `composer style:fix` applies the style rules for you.

There is a fourth check worth running when you touch anything outside `src/`:

```bash
make package-check
```

It builds the distributable the way `.gitattributes` says it ships, installs it
**copied** into a throwaway application, and boots it. A path repository is a
symlink, so the skeleton booting proves nothing about whether a file was left
out of the package.

## What a good change looks like

- **A test that fails before and passes after.** Everything runs against
  in-memory SQLite, so there is no fixture database to set up.
- **A comment where the reason is not obvious from the code.** Say why, not
  what. The existing source is the reference for tone and density.
- **Types that PHPStan can follow.** Level 6 means every array needs a value
  type in its docblock.

## The design rules

These are not style preferences; a change that breaks one needs a strong
argument.

- **No facades, no global helpers, no service locator.** If a class needs
  something it asks for it in the constructor.
- **No magic strings.** Route handlers are `[Controller::class, 'method']`.
- **No hidden global state.** Active Record's static connection is the one
  documented exception, because the pattern cannot work without it.
- **Secure by default.** Mass assignment is opt-in, uploads are identified by
  their bytes rather than their filename, and values are bound rather than
  interpolated into SQL.
- **The core stays small.** New capability should usually arrive as a service
  provider, a middleware, a view engine, or a validation rule registered with
  `Validator::extend()`, all of which are extension points that already exist.
  A required dependency added to the framework needs a real justification.

## Reporting a bug

A failing test is the best bug report. Second best is the smallest snippet that
reproduces it, plus your PHP version and database driver. A good number of
sharp edges only show up on MySQL, where PDO returns every column as a string.

## Security

Please do not open a public issue for a security problem. See
[SECURITY.md](SECURITY.md).
