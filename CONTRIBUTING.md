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

### Mutation testing

A green suite proves the tests ran, not that they would notice if the code
broke. `bin/mutate` breaks the source one edit at a time (flipping a
comparison, inverting a boolean) and reruns the suite. A mutant that survives
is a line that could be wrong with every test still passing.

```bash
composer mutate                 # against SQLite
make db-up && make mutate-drivers   # against all three
```

The bar is 100% across the driver matrix. That is stricter than it sounds,
because coverage is driver-dependent: a branch that only differs on Postgres
cannot be killed by a SQLite run. When a mutant survives, one of three things
is true, in rough order of likelihood:

1. **A test is missing.** Write it.
2. **The mutant is equivalent.** The edit is real but cannot change behaviour.
   Mark the line `// mutation:ignore <reason>`. The reason is not optional.
3. **The code is dead.** Delete it. Several survivors turned out to be
   redundant guards and unreachable fallbacks.

`bin/mutate` edits real files, so do not run anything else against the working
tree while it is going, a concurrent test run or `bin/fuzz` will read whichever
mutant happens to be applied and report a bug that does not exist. It puts every
file back on the way out, including on Ctrl-C, a CI timeout or a fatal error,
and verifies before printing a score that it actually did. If it ever reports
that it left the tree modified, `git checkout` before trusting anything you ran
after it.

### Fuzzing

Mutation testing asks whether the tests would notice the code changing. Fuzzing
asks the other question: whether the code notices *input* changing. `bin/fuzz`
throws things no reasonable caller would send (control bytes, overlong UTF-8,
traversal sequences, integer boundaries, serialised objects) at every parser.

```bash
make fuzz                    # 50,000 cases from a random seed
./bin/fuzz --seed=12345      # replay a reported failure exactly
```

The rule each entry point is held to is **reject whatever you like, but reject
it deliberately**. An exception the method documents is a pass: the input was
understood and refused. A `TypeError`, `ValueError` or `Error` is a failure,
because it means the value reached code that had assumed it could not exist.
That is the line between handling something and happening to survive it.

If you add a parser, add it to the target list in `bin/fuzz` and say which
exceptions it is allowed to throw. And when you add a target, check that it can
actually fail: break the guard on purpose, confirm the fuzzer catches it, then
put it back. A target whose assertion is too weak to fail is worse than no
target, because it reads as coverage. One here compared an unresolved path
against its own root prefix, which passes for `/root/../etc/passwd`; it was
found exactly this way.

### Packaging

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
