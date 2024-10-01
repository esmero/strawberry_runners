<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 2:12 PM
 */

namespace Drupal\strawberry_runners\Plugin;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Plugin\DefaultPluginManager;


/**
 * Provides the Strawberry Runners Postprocessor Plugin Manager.
 *
 * Class StrawberryRunnersPostProcessorPluginManager
 *
 * @package Drupal\strawberryfield\Plugin
 */
class StrawberryRunnersPostProcessorPluginManager extends DefaultPluginManager{

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler
  ) {
    parent::__construct(
      'Plugin/StrawberryRunnersPostProcessor',
      $namespaces,
      $module_handler,
      'Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface',
      'Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor'
    );

    $this->alterInfo('strawberry_runners_strawberryrunnerspostprocessor_info');
    $this->setCacheBackend($cache_backend,'strawberry_runners_strawberryrunnerspostprocessor_plugins');
  }

}
