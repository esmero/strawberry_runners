<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 2:24 PM
 */

namespace Drupal\strawberry_runners\Plugin;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Component\Plugin\DependentPluginInterface;
use \Drupal\Component\Plugin\ConfigurableInterface;


/**
 * Defines and Interface for StrawberryRunnersPostProcessor Plugins
 *
 * Interface StrawberryRunnersPostProcessorPluginInterface
 *
 * @package Drupal\strawberry_runners\Plugin
 */
interface StrawberryRunnersPostProcessorPluginInterface extends PluginInspectionInterface, PluginWithFormsInterface, DependentPluginInterface, ConfigurableInterface {

  /**
   * When running the Processor, TEST means no permanent storage of output
   */
  const TEST = 0;

  /**
   * When running the Processor, PROCESS means permanent storage of output
   */
  const PROCESS = 1;

  /**
   * When running the Processor, BENCHMARK means keeping track of stats
   */
  const BENCHMARK = 2;

  /**
   * Different Types of Outputs a processor can have
   */
  const OUTPUT_TYPE = [
    'subkey' => 'subkey',
    'ownkey' => 'ownkey',
    'file' => 'file',
    'plugin' => 'plugin',
    'searchapi' => 'searchapi'
  ];

  /**
   * Provides a list of Post Processor Plugins
   *
   * @param string $config_entity_id
   *   The Config Entity's id where this plugin instance's config is stored.
   *   This value comes from the config entity used to store all this settings
   *   and needed to generate separate cache bins for each
   *   Plugin Instance.
   *
   * @return mixed
   */

  public function label();

  public function onDependencyRemoval(array $dependencies);

  /**
   * Executes the logic of this plugin given a file path and a context.
   *
   * @param \stdClass $io
   *    $io->input needs to contain \Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor::$input_property
   *    $io->output will contain the result of the processor
   * @param string $context
   *   Can be either of
   *    StrawberryRunnersPostProcessorPluginInterface::PROCESS
   *    StrawberryRunnersPostProcessorPluginInterface::TEST
   *    StrawberryRunnersPostProcessorPluginInterface::BENCHMARK
   *  Each plugin needs to know how to deal with this.
   *
   */
  public function run(\stdClass $io, $context = StrawberryRunnersPostProcessorPluginInterface::PROCESS);

}
