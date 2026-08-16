# View engines

[← back to the README](../README.md)

Set `views.engine` and the rest of the framework does not care which you picked.

| Engine   | Templates    | Notes                                          |
| -------- | ------------ | ---------------------------------------------- |
| `'twig'` | `.twig`      | Auto-escaped. Needs `composer require twig/twig`. |
| `'php'`  | `.php`       | Plain PHP. No compiler, no cache, no new syntax. |
| `'html'` | `.html`      | Static files with `{{ placeholder }}` substitution. |
| `'none'` | (none)       | API only. No view layer is built at all.        |

A plain-PHP template, with layout support and an escape helper:

```php
<?php $this->layout('layout', ['title' => $post['title']]) ?>

<h1><?= $e($post['title']) ?></h1>
<a href="<?= $e($route('posts.index')) ?>">All posts</a>
```

Bring your own by implementing `Phpvin\View\Engine` (three methods) and
passing an instance or closure as `views.engine`.
