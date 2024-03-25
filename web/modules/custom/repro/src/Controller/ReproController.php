<?php
namespace Drupal\repro\Controller;

use Drupal\Core\Controller\ControllerBase;

class ReproController extends ControllerBase {
    public function display() {
        return [
            '#title' => 'Repro Page',
            '#markup' => '<p>Test</p>',
        ];
    }
}
