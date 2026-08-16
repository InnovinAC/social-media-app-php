<?php $this->layout('layout', ['title' => 'Greeting']) ?>
<h1>Hello, <?= $e($name) ?></h1>
<a href="<?= $e($route('greeting', ['name' => 'ada'])) ?>">again</a>
