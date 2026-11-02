<?php

namespace Drupal\strawberry_runners\Attribute;
use Drupal\Component\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a StrawberryRunnersPostProcessor item Attribute object.
 *
 * Class StrawberryRunnersPostProcessor
 *
 * @package Drupal\strawberry_runners\Attribute
 *
 * @Annotation
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class StrawberryRunnersPostProcessor extends Plugin {

  const PRESAVE = 'preSave';
  const POSTSAVE = 'postSave';
  /**
   *  Constructs a ImporterAdapter attribute.
   *
   * @param string $id
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   * @param bool $remote
   * @param bool $batch
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly string $input_type = '',
    public readonly string $output_type = '',
    public readonly string $input_property = '',
    public readonly ?string $input_argument = NULL,
    public readonly string $when = StrawberryRunnersPostProcessor::POSTSAVE,
    public readonly ?TranslatableMarkup $description = NULL,
  ) {}
}
