<?php
$types = ['message' => 'success', 'success' => 'success', 'error' => 'danger', 'warning' => 'warning'];

foreach ($types as $key => $class) {
    $value = session()->getFlashdata($key);

    if (! $value) {
        continue;
    }

    foreach ((array) $value as $text) {
        echo '<div class="alert alert-' . $class . ' alert-dismissible fade show" role="alert">' . esc($text)
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
}

if ($errors = session()->getFlashdata('errors')) {
    foreach ((array) $errors as $text) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . esc($text)
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
}
