<?php
// Χρειάζεται όρισμα (φάκελος προορισμού, εκτός site) — το περνάμε μέσω $args
// (eval-file) ή $argv (CLI), το ίδιο μοτίβο με τα υπόλοιπα loaders.
$GLOBALS['argv'] = array_merge(['purge-orphan-documents.php'], $args ?? $argv ?? []);
require __DIR__ . '/purge-orphan-documents.php';
