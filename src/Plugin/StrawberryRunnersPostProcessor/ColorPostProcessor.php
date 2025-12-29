<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 05/22/24
 * Time: 8:07AM
 */

namespace Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessor;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\strawberry_runners\Annotation\StrawberryRunnersPostProcessor;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginInterface;
use Drupal\strawberry_runners\Plugin\StrawberryRunnersPostProcessorPluginBase;
use Drupal\strawberry_runners\VTTLine;
use Drupal\strawberry_runners\VTTProcessor;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberry_runners\Web64\Nlp\NlpClient;
use Laracasts\Transcriptions\Transcription;
use League\ColorExtractor\Palette;
use Phpml\Math\Matrix;

/**
 *
 * ColorPostProcessor
 *
 * @StrawberryRunnersPostProcessor(
 *    id = "color",
 *    label = @Translation("Post processor that extracts primary colors from images"),
 *    input_type = "entity:file",
 *    input_property = "filepath",
 *    input_argument = "annotation"
 * )
 */
class ColorPostProcessor extends StrawberryRunnersPostProcessorPluginBase {

  public $pluginDefinition;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'source_type' => 'asstructure',
        'mime_type' => ['image/jpeg'],
        'output_type' => 'json',
        'output_destination' => 'searchapi',
        'processor_queue_type' => 'background',
        'language_key' => 'language_iso639_3',
        'language_default' => 'eng',
        'timeout' => 300,
        'image_server' => 'http://esmero-cantaloupe'
      ] + parent::defaultConfiguration();
  }

  public function settingsForm(array $parents, FormStateInterface $form_state) {
    $element = parent::settingsForm($parents, $form_state);
    $element['source_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The type of source data this processor works on'),
      '#options' => [
        'asstructure' => 'File entities referenced in the as:filetype JSON structure',
      ],
      '#default_value' => $this->getConfiguration()['source_type'],
      '#description' => $this->t('Select from where the source data this processor needs is fetched'),
      '#required' => TRUE,
    ];

    $element['ado_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ADO type(s) to limit this processor to.'),
      '#default_value' => $this->getConfiguration()['ado_type'],
      '#description' => $this->t('A single ADO type or a coma delimited list of ado types that qualify to be Processed. Leave empty to apply to all ADOs.'),
    ];

    $element['jsonkey'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('The JSON key that contains the desired source.'),
      '#options' => [
        'as:image' => 'as:image',
      ],
      '#default_value' => (!empty($this->getConfiguration()['jsonkey']) && is_array($this->getConfiguration()['jsonkey'])) ? $this->getConfiguration()['jsonkey'] : [],
      '#states' => [
        'visible' => [
          ':input[name="pluginconfig[source_type]"]' => ['value' => 'asstructure'],
        ],
      ],
      '#required' => TRUE,
    ];

    $element['mime_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mimetypes(s) to limit this Processor to.'),
      '#default_value' => $this->getConfiguration()['mime_type'],
      '#description' => $this->t('A single Mimetype type or a comma separated list of mimetypes that qualify to be Processed. Leave empty to apply any file'),
      '#states' => [
        'visible' => [
          ':input[name="pluginconfig[source_type]"]' => ['value' => 'asstructure'],
        ],
      ],
    ];


    $element['output_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The expected and desired output of this processor.'),
      '#options' => [
        'json' => 'Data/Values that can be serialized to JSON',
      ],
      '#default_value' => $this->getConfiguration()['output_type'],
      '#description' => $this->t('This processors only generate JSON'),
    ];

    $element['output_destination'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t("Where and how the output will be used."),
      '#options' => [
        'plugin' => 'As Input for another processor Plugin',
        'searchapi' => 'In a Search API Document using the Strawberryfield Flavor Data Source (e.g used for ML Vector Comparison)',
      ],
      '#default_value' => (!empty($this->getConfiguration()['output_destination']) && is_array($this->getConfiguration()['output_destination'])) ? $this->getConfiguration()['output_destination'] : [],
      '#description' => t('As Input for another processor Plugin will only have an effect if another Processor is setup to consume this output.'),
      '#required' => TRUE,
    ];

    $element['processor_queue_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The queue to use for this processor.'),
      '#options' => [
        'background' => 'Secondary queue in background',
        'realtime' => 'Primary queue in realtime',
      ],
      '#default_value' => $this->getConfiguration()['processor_queue_type'],
      '#description' => $this->t('The primary queue will be execute in realtime while the Secondary will be execute in background'),
      '#required' => TRUE,
    ];

    $element['source_type'] = [
      '#type' => 'select',
      '#title' => $this->t('The type of source data this processor works on'),
      '#options' => [
        'asstructure' => 'File entities referenced in the as:filetype JSON structure',
      ],
      '#default_value' => $this->getConfiguration()['source_type'],
      '#description' => $this->t('This processor only works on Files, in specific images.'),
      '#required' => TRUE,
    ];

    $element['use_inherited_region'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Re-use region (detection boundary) from a Parent (ML processor) detection bounding boxes. Allows Color extraction to run on a previously detected sub-region'),
      '#default_value' => $this->getConfiguration()['use_inherited_region'],
      '#description' => $this->t('If this  processor is chained (child) of an ML processor, the cropped/inherited region (one to many) passed by that processor will be used instead of the complete image. This has no effect if this processor is the root of a chain.'),
      '#required' => TRUE,
    ];

    $element['iiif_server'] = [
      '#type' => 'url',
      '#title' => $this->t('The IIIF Server to use for Image ML'),
      '#default_value' => $this->getConfiguration()['iiif_server'] ?: \Drupal::service('config.factory')
        ->get('format_strawberryfield.iiif_settings')
        ->get('int_server_url'),
      '#description' => $this->t('The IIIF Server to use. By default we will use the Internal (esmero-cantaloupe) endpoint'),
      '#required' => TRUE,
    ];

    return $element;
  }


  public function run(\stdClass $io, $context = StrawberryRunnersPostProcessorPluginInterface::PROCESS) {
    $input_property = $this->pluginDefinition['input_property'];
    $input_argument = $this->pluginDefinition['input_argument'];
    $file_uuid = isset($io->input->metadata['dr:uuid']) ? $io->input->metadata['dr:uuid'] : NULL;
    $node_uuid = isset($io->input->nuuid) ? $io->input->nuuid : NULL;

    if (isset($io->input->{$input_property}) && $file_uuid && $node_uuid) {
      $config = $this->getConfiguration();
      $timeout = $config['timeout']; // in seconds
      $output = new \stdClass();
      $output->plugin = NULL;

      $file_languages = isset($io->input->lang) ? (array) $io->input->lang : [$config['language_default'] ? trim($config['language_default'] ?? '') : 'eng'];
      // Fixed, passed by the AbstractPostProcessor always, even if input_argument is different.
      $sequence_id = isset($io->input->sequence_id) && !is_numeric($io->input->sequence_id) ? $io->input->sequence_id : 1;
      $sequence_number = isset($io->input->sequence_number) && !is_numeric($io->input->sequence_number) ? $io->input->sequence_number : 1;
      $internal_sequence_id = isset($io->input->internal_sequence_id) && !is_numeric($io->input->internal_sequence_id) ? $io->input->internal_sequence_id : 1;
      $annotation = isset($io->input->{$input_argument}) && !is_numeric($io->input->{$input_argument}) ? $io->input->{$input_argument} : '{}';
      $json_annotation = json_decode($annotation, TRUE);
      $iiif_image_url_region = NULL;
      $inherit_region = FALSE;
      if (json_last_error() == JSON_ERROR_NONE) {
        if (!empty($json_annotation)) {
          $iiif_image_url_region = $json_annotation['x'] . ',' . $json_annotation['y'] . ',' . $json_annotation['w'] . ',' . $json_annotation['h'];
          if ($config['use_inherited_region']) {
            $inherit_region = TRUE;
          }
        }
      }
      else {
        $this->logger->warning("@sbr_processor: Color extraction from Image file with UUID @file_uuid failed for ADO with UUID @node_uuid with sequence number @sequence_id",
          [
            '@sbr_processor' => $this->getPluginId(),
            '@node_uuid' => $node_uuid ?? 'ABSENT',
            '@file_uuid' => $file_uuid ?? 'ABSENT',
            '@sequence_id' => $sequence_number,
          ]);
        throw new \Exception("Could not extract Color bc of a chained processor provided a wrong Annotation region.");
      }

      setlocale(LC_CTYPE, 'en_US.UTF-8');
      $width = $io->input->metadata['flv:identify'][$io->input->{$input_argument}]['width'] ?? NULL;
      $height = $io->input->metadata['flv:identify'][$io->input->{$input_argument}]['height'] ?? NULL;
      if (!($width && $height)) {
        $width = $io->input->metadata['flv:exif']['ImageWidth'] ?? NULL;
        $height = $io->input->metadata['flv:exif']['ImageHeight'] ?? NULL;
      }
      $iiifidentifier = urlencode(
        StreamWrapperManager::getTarget(isset($io->input->metadata['url']) ? $io->input->metadata['url'] : NULL)
      );

      if ($iiifidentifier == NULL || empty($iiifidentifier) || empty($width) || empty($height)) {
        return $output;
      }

      if (!$iiif_image_url_region) {
        $iiif_image_url_region = 'full';
      }

      $quality = 'default.jpg';
      $iiif_image_url = $config['iiif_server'] . "/{$iiifidentifier}/{$iiif_image_url_region}/!320,320/0/{$quality}";

      $labels = [];
      $page_text = NULL;

      $labels = [];

      $annotations = [];
      $output->searchapi['vector_3'] = isset($ML['vision_transformer']['vector']) && is_array($ML['vision_transformer']['vector']) && count($ML['vision_transformer']['vector']) == 768 ? $ML['vision_transformer']['vector'] : NULL;
      $output->searchapi['vector_12'] = [];
      $output->searchapi['fulltext'] = '';
      $output->searchapi['metadata'] = $labels;
      $output->searchapi['service_md5'] = NULL;
      $output->searchapi['plaintext'] = '';
      $output->searchapi['processlang'] = $file_languages;
      $output->searchapi['ts'] = date("c");
      $output->searchapi['label'] = $this->t("Image Color Vectors") . ' ' . $sequence_number;
      $output->plugin['searchapi'] = $output->searchapi;
      $output->plugin['annotation'] = $annotations;
      $io->output = $output;
    }
    else {
      throw new \Exception("Invalid argument for OCR processor");
    }

  }



  public function sRGBtoCAM02UCS(array $rgb) {



  }

  public function sRGBtoCIEXYZ(array $rgb):Matrix {
    // IEC 61966-2-1:1999
    // $rgb should already be pre/divided by 100 and float.

    $M = new Matrix([
      [0.4124,0.3576,0.1805],
      [0.2126 ,0.7152,0.0722],
      [0.0193,0.1192,0.9505]
    ]);
    $XYZ = $this->sGammaInv($rgb)->multiply($M->transpose());
    $XYZ = $this->capMatrix($XYZ, 0, 1);
    return $XYZ;
  }

  public function sGammaInv(array $rgb): Matrix {
    /* function out = sGammaInv(inp)
    % Inverse gamma correction: Nx3 sRGB -> Nx3 linear RGB.
    idx = inp > 0.04045;
    out = inp / 12.92;
    out(idx) = real(((inp(idx) + 0.055) ./ 1.055) .^ 2.4);
    end
    */
    // Note. Validateded. Provides same output as sGammaInv/Mathlab
    $linear_values = [];
    foreach($rgb as $index => $component) {
      if ($component <= 0.04045) {
        // Linear dark component values
        $linear_values[$index] = $component / 12.92;
      } else {
        // Curve for brighter component values (gamma 2.4)
        $linear_values[$index]= (($component + 0.055) / 1.055) ** 2.4;
      }
    }
    return new Matrix($linear_values);
  }


  public function CIEXYZtoCIECAM02(Matrix $XYZ, \stdClass $prm) {
/*
    %
    %% Conversion %%
%
%%% Step 1: cone responses (CAT02) %%%
%

RGB = (100*XYZ) * prm.M_CAT02.';
*/
 $RGB = $XYZ->multiplyByScalar(100)->multiply($prm->M_CAT02->traspose());
    /*
%
%%% Step 2: chromatic adaptation (cone responses considering luminance and surround) %%%
%
RGB_C = bsxfun(@times, prm.RGB_c, RGB);
%
    */
    // OK this one is hard. bscfun does dymensional expansion or broadcasting so two compatible matrices (but different dimensions)
    // see https://numpy.org/doc/stable/user/basics.broadcasting.html
    // can be applied a per component operation. In this case @times is equivalent of
    // our  $this->hadamardProduct
    // But will only work if $prm->RGB_c and $RGB are made equivalent for the operation
    // By duplicating values ...
    // mmm.... Unclear though bc both prm->RGB_c and $XYZ are both [1,3] already ? woo
    // really no need to expand? weird.

    $RGB_C = $this->hadamardProduct($prm->RGB_c, $RGB);


    /*

     *
%%% Step 3: Hunt-Pointer-Estevez response %%%
%
RGBp = RGB_C * (prm.M_HPE / prm.M_CAT02).';
%
%%% Step 4: post-adaption cone response (nonlinear compression) %%%
%
if prm.isns
tmp = ((prm.F_L.*abs(RGBp))./100).^0.42;
	RGBp_a = 400 .* sign(RGBp) .* tmp ./ (tmp+27.13);
else % CIE
	RGBp_signs = sign(RGBp);
	tmp = (prm.F_L .* bsxfun(@times,RGBp_signs,RGBp)/100).^0.42;
	RGBp_a = 400 .* RGBp_signs .* tmp ./ (tmp+27.13) + 0.1;
end
%
%%% Step 5: hue angle & opponent color dimensions a (red-green) & b (yellow-blue) %%%
%
a = RGBp_a*([11;-12;1]./11);
b = RGBp_a*([1;1;-2]./9);
h_rad = atan2(b,a);
h = mod(180*h_rad/pi, 360);
%
%%% Step 6: hue composition (using unique hue data) %%%
%
hp = h + 360*(h < prm.h_i(1));
tmp = bsxfun(@le,prm.h_i,hp.');
tmp = flipud(cumsum(flipud(tmp),1))==1;
[idx,~] = find(tmp);
tmp = (hp - prm.h_i(idx)) ./ prm.e_i(idx);
H = prm.H_i(idx) + (100*tmp) ./ (tmp + (prm.h_i(idx+1)-hp) ./ prm.e_i(idx+1));
%
%%% Step 7: achromatic response %%%
%
if prm.isns
	A = (RGBp_a*[2;1;1/20]) .* prm.N_bb;
else % CIE
	A = (RGBp_a*[2;1;1/20] - 0.305) .* prm.N_bb;
end
A(A<0) = 0 / ~(nargin<3 || isn);
%
%%% Step 8: correlate of lightness %%%
%
J = 100*(A ./ prm.A_w).^(prm.c.*prm.z); % lightness
%
%%% Step 9: correlate of brightness %%%
%
Q = (4./prm.c) .* sqrt(J/100) .* (prm.A_w+4) .* sqrt(sqrt(prm.F_L)); % brightness
%
%%% Step 10: correlates of chroma, colorfulness, and saturation %%%
%
e = (12500/13) .* prm.N_c .* prm.N_cb .* (cos(h_rad+2) + 3.8); % eccentricity factor
if prm.isns
	tmp = e .* sqrt(a.^2 + b.^2) ./ (RGBp_a*[1;1;21/20]+0.305);
else % CIE
	tmp = e .* sqrt(a.^2 + b.^2) ./ (RGBp_a*[1;1;21/20]);
end
C = tmp.^0.9 .* sqrt(J/100) .* (1.64 - 0.29.^prm.n).^0.73; % chroma
M = C .* sqrt(sqrt(prm.F_L)); % colorfulness
if prm.isns
	s = 50 .* sqrt((C.*prm.c) ./ ((prm.A_w+4).*sqrt(J/100)));
	s(J==0 | s==0) = 0; % saturation
else % CIE
	s = 100*sqrt(M ./ Q); % saturation
end
%
out = struct('J',J,'Q',Q,'C',C,'M',M,'s',s,'H',H,'h',h);
out = structfun(@(v)reshape(v,isz), out, 'UniformOutput',false); */




  }

  public function CIECAM02toCAM02UCS(array $ciecam02, $params) {

  }

  private function CIECAM02_parameters() {
    // @see https://github.com/DrosteEffect/CIECAM02/blob/master/CIECAM02_parameters.m
    // White point fixed to 'D65'
    // For future refence, this Mathlab compiler is helping me with translation
    // https://www.mycompiler.io/new/octave
    $prm = new \stdClass();
    // TODO. should be [ $wp ] instead of $wp. Matrix constructor does that automatically ?
    $wp = [0.95682,1,0.92149];
    // $Y_b = NumericScalar, relative luminance factor of the background.
    $Y_b = 20;
    // $L_A = NumericScalar, adapting field luminance (cd/m^2).
    $L_A = 64/M_PI/5;
    // $sur = CharRowVector, one of 'dim'/'dark'/'average'**.
    $sur = 'average';
    //$prm.XYZ_w = 100*double(reshape(wp,1,[]));
    $prm->XYZ_w = array_map(function($value) {
      return (float) $value  * 100;
    }, $wp);
    $prm->XYZ_w = new Matrix($prm->XYZ_w);
    //$prm_isns = TRUE //
    $prm->F = 1.0;
    $prm->c = 0.690;
    $prm->N_c = 1.00;
    /*
     * prm.M_CAT02 = [...
	+0.7328,+0.4296,-0.1624;...
	-0.7036,+1.6975,+0.0061;...
	+0.0030,+0.0136,+0.9834];
%
prm.M_HPE = [...
	+0.38971,+0.68898,-0.07868;...
	-0.22981,+1.18340,+0.04641;...
	+0      ,+0      ,+1      ];
     */
    $prm->M_CAT02 = new Matrix([
      [0.7328, 0.4296, -0.1624],
      [-0.7036, 1.6975, 0.0061],
      [0.0030, 0.0136, 0.9834],
    ]);

    $prm->M_HPE = new Matrix([
      [0.38971, 0.68898, -0.07868],
      [-0.22981, 1.18340, 0.04641],
      [0, 0, 1],
    ]);
    $prm->h_i = new Matrix([[20.14],[90.00],[164.25],[237.53],[380.14]]);
    $prm->e_i = new Matrix([[0.8],[0.7],[1.0],[1.2],[0.8]]);
    $prm->H_i = new Matrix([[0],[100],[200],[300],[400]]);
    /*
     * %
    %% Derive Parameters %%
    %
    prm.RGB_w = prm.XYZ_w * prm.M_CAT02.';
    prm.D = prm.F .* (1-(1/3.6) .* exp(-(L_A+42)/92));
    prm.D = max(0,min(1,prm.D));
    %
     */
    $prm->RGB_w = $prm->XYZ_w->multiply($prm->M_CAT02->transpose());
    $prm->D = $prm->F * ((1-(1/3.6)) * exp(-(L_A+42)/92));
    $prm->D = max(0,min(1, $prm->D));

    /*
prm.RGB_c = prm.D*prm.XYZ_w(2) ./ prm.RGB_w + 1 - prm.D;
prm.RGBp_w = (prm.RGB_c .* prm.RGB_w) * (prm.M_HPE / prm.M_CAT02).';
    */

    $prm->RGB_c = $this->hadamardDivision($this->componentSum($prm->RGB_w,  1 - $prm->D),$prm->D * $prm->XYZ_w->toArray()[1], TRUE);
    // Formal Division  of two matrices (not component/Hadamard) is the same as multiplying against the inverse of the divisor
    $prm->RBGp_w = $this->hadamardProduct($prm->RGB_w,  $prm->RGB_c)->multiply($prm->M_HPE->multiply($prm->M_CAT02->inverse()));
    /*

% Michaelis-Menten equation for the luminance level adaption factor:
prm.k = 1 ./ (5*L_A+1);
prm.F_L = (prm.k.^4 .* (5*L_A))/5 + ((1-prm.k.^4).^2 .* (5*L_A).^(1/3))/10;
%
prm.n = Y_b ./ prm.XYZ_w(2);
prm.z = 1.48 + sqrt(prm.n);
prm.N_bb = 0.725 * prm.n.^(-1/5);
prm.N_cb = prm.N_bb;
%
tmp = ((prm.F_L .* prm.RGBp_w)/100).^0.42;
*/
    // Michaelis-Menten equation for the luminance level adaption factor:

    $prm->k = 1 / (5 * $L_A + 1);
    $prm->F_L = ($prm->k ** 4 * (5 * $L_A)) / 5 + ((1 - $prm->k ** 4 ) ** 2 * (5*$L_A) ** (1/3))/10;
    $prm->n = $Y_b / $prm->XYZ_w->toArray()[1];
    $prm->z = 1.48 + sqrt( $prm->n);
    $prm->N_bb = $prm->N_cb = 0.725  * $prm->n ** (-1/5);
    $tmp =  $this->hadamardPow($this->hadamardProduct($prm->RBGp_w, $prm->F_L), 0.42);


    /*
   if prm.isns
     prm.RGBp_aw = 400*(tmp ./ (27.13 + tmp));
     prm.A_w = (prm.RGBp_aw * [2;1;1/20]) * prm.N_bb;
   else % CIE
     prm.RGBp_aw = 400*(tmp ./ (27.13 + tmp)) + 0.1;
     prm.A_w = (prm.RGBp_aw * [2;1;1/20] - 0.305) * prm.N_bb;
   end
   %
        */
    // Nico Schlömer's algorithmic improvements.
    $prm->RGBp_aw = $this->hadamardProduct($this->hadamardDivision($tmp, $this->componentSum($tmp, 27.13)), 400);
    $prm->A_w = $prm->RGBp_aw->multiply(New Matrix([[2],[1],[1/20]]))->multiplyByScalar($prm->N_bb);
    return $prm;
  }

  public function capMatrix(Matrix $matrix, $min, $max) {
    $matrix_data = $matrix->toArray();
    $rows = $matrix->getRows();
    $cols = $matrix->getColumns();
    $capped = [];
      for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
          $capped[$i][$j] = max($min, min($max, $matrix_data[$i][$j]));
        }
      }
      return new Matrix($capped);
    }


  public function hadamardProduct(Matrix $matrix, mixed $othermatrixornumber) {
    $matrix_data = $matrix->toArray();
    $rows = $matrix->getRows();
    $cols = $matrix->getColumns();
    $hadamard = [];
    if ($othermatrixornumber instanceof Matrix) {
      if ($rows != $othermatrixornumber->getRows() || $cols != $othermatrixornumber->getColumns()) {
        // TODO: They just need to have same colums per row ... but having different number of rows is OK
        throw new \InvalidArgumentException("Both Input Matrices need to have the same dimensions");
      }
      $othermatrix_data = $othermatrixornumber->toArray();
      for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
          $hamard[$i][$j] = $matrix_data[$i][$j] * $othermatrix_data[$i][$j];
        }
      }
    }
    elseif (is_numeric($othermatrixornumber)) {
      for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
          $hamard[$i][$j] = $matrix_data[$i][$j] * $othermatrixornumber;
        }
      }
    }
    else {
      throw new \InvalidArgumentException("Second input needs to be a Matrix or a numeric value");
    }
    return new Matrix($hadamard);
  }

  public function hadamardPow(Matrix $matrix, mixed $othermatrixornumber) {
    $matrix_data = $matrix->toArray();
    $rows = $matrix->getRows();
    $cols = $matrix->getColumns();
    $hadamard = [];
    if ($othermatrixornumber instanceof Matrix) {
      if ($rows != $othermatrixornumber->getRows() || $cols != $othermatrixornumber->getColumns()) {
        // TODO: They just need to have same colums per row ... but having different number of rows is OK
        throw new \InvalidArgumentException("Both Input Matrices need to have the same dimensions");
      }
      $othermatrix_data = $othermatrixornumber->toArray();
      for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
          $hamard[$i][$j] = $matrix_data[$i][$j] ** $othermatrix_data[$i][$j];
        }
      }
    }
    elseif (is_numeric($othermatrixornumber)) {
      for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
          $hamard[$i][$j] = $matrix_data[$i][$j] ** $othermatrixornumber;
        }
      }
    }
    else {
      throw new \InvalidArgumentException("Second input needs to be a Matrix or a numeric value");
    }
    return new Matrix($hadamard);
  }

  public function hadamardDivision(Matrix $matrix, mixed $othermatrixornumber, $invert = FALSE) {
    $matrix_data = $matrix->toArray();
    $rows = $matrix->getRows();
    $cols = $matrix->getColumns();
    $hadamard = [];
    if ($othermatrixornumber instanceof Matrix) {
      if ($rows != $othermatrixornumber->getRows() || $cols != $othermatrixornumber->getColumns()) {
        // TODO: They just need to have same colums per row ... but having different number of rows is OK
        throw new \InvalidArgumentException("Both Input Matrices need to have the same dimensions");
      }
      $othermatrix_data = $othermatrixornumber->toArray();

      for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
          $hamard[$i][$j] = !$invert ? $matrix_data[$i][$j] / $othermatrix_data[$i][$j] : $othermatrix_data[$i][$j] / $matrix_data[$i][$j];
        }
      }
    }
    elseif (is_numeric($othermatrixornumber)) {
      for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
          $hamard[$i][$j] = !$invert ?  $matrix_data[$i][$j] / $othermatrixornumber : $othermatrixornumber /  $matrix_data[$i][$j];
        }
      }
    }
    else {
      throw new \InvalidArgumentException("Second input needs to be a Matrix or a numeric value");
    }
    return new Matrix($hadamard);
  }


  public function componentSum(Matrix $matrix, mixed $othermatrixornumber) {
    $matrix_data = $matrix->toArray();
    $rows = $matrix->getRows();
    $cols = $matrix->getColumns();
    $hadamard = [];
    if ($othermatrixornumber instanceof Matrix) {
      if ($rows != $othermatrixornumber->getRows() || $cols != $othermatrixornumber->getColumns()) {
        // TODO: They just need to have same colums per row ... but having different number of rows is OK
        throw new \InvalidArgumentException("Both Input Matrices need to have the same dimensions");
      }
      $othermatrix_data = $othermatrixornumber->toArray();
      for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
          $hamard[$i][$j] = $matrix_data[$i][$j] + $othermatrix_data[$i][$j];
        }
      }
    }
    elseif (is_numeric($othermatrixornumber)) {
      for ($i = 0; $i < $rows; $i++) {
        for ($j = 0; $j < $cols; $j++) {
          $hamard[$i][$j] = $matrix_data[$i][$j] + $othermatrixornumber;
        }
      }
    }
    else {
      throw new \InvalidArgumentException("Second input needs to be a Matrix or a numeric value");
    }
    return new Matrix($hadamard);
  }




}
