<?php
/**
 * @file: Handlers definition.
 */

namespace Octopus;

class Handlers {
  protected $result;
  protected $path = '';
  public function __construct(Config $config, Result $result) {
    $this->result = $result;
  }

}
