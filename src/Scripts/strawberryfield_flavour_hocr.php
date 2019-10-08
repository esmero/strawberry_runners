<?php
use Drupal\Core\State\State;

$child_id = $extra[0];

//Pull running item status (node_id and SBF-JSON)
$item_state_data = unserialize(\Drupal::state()->get('strawberryfield_runningItem'));
$element = unserialize($item_state_data['item']->data);
$node_id = $element[0];
$jsondata = $element[1];
$flavour_toProcess = $element[2];

$flavour_type = explode('|', $child_id)[3];
$flavour_status = explode('|', $child_id)[4];
$flavour_node_id = explode('|', $child_id)[0];
$flavour_mainContainer_key = explode('|', $child_id)[1];
$flavour_subContainer_key = explode('|', $child_id)[2];

$uri = $jsondata[$flavour_mainContainer_key][$flavour_subContainer_key]['url'];

//get image path
$stream = \Drupal::service('stream_wrapper_manager')->getViaUri($uri);
$image_path = $stream->realpath();

//call external command
unset($hocr_output);
unset($hocr_array_output);
exec('tesseract ' . $image_path . ' stdout -l eng hocr', $hocr_output, $hocr_return);

if ($hocr_return == 0) {
  $hocr_string_output = implode("", $hocr_output);
  $hocr_string = str_replace('xmlns=', 'ns=', $hocr_string_output);
  $xml = simplexml_load_string($hocr_string);
  $body = $xml->body;

  unset($hOCR);
  //extract page boundary
  $result = $xml->body->xpath("//div[@class='ocr_page']");
  preg_match('/bbox\s[0-9]+\s[0-9]+\s[0-9]+\s[0-9]+/',$result[0]->attributes()['title'], $matches);
  $hocr_page_bbox = str_replace('bbox ', '', $matches[0]);
  $hOCR['page'] = $hocr_page_bbox;

  //extract words and boundary
  $result = $xml->body->xpath("//span[@class='ocrx_word']");
  foreach ($result as $word) {
    $hocr_word = (string)$word[0];
    if ($hocr_word != ' ') {
      preg_match('/bbox\s[0-9]+\s[0-9]+\s[0-9]+\s[0-9]+/', $word->attributes()['title'], $matches);
      $hocr_word_bbox = str_replace('bbox ', '', $matches[0]);
      $hOCR['words'][] = array("word" => $hocr_word, "bbox" => $hocr_word_bbox);
    }
  }
  $hOCR_json_output = json_encode($hOCR);
  //we don't want fill SBF-JSON with words so
  $hOCR_json_output = '{"hOCR return OK":"' . $hocr_return . '"}';
}
else {
  $hOCR_json_output = '{"hOCR return error":"' . $hocr_return . '"}';
}

//output on queue child output
$childQueue_output = \Drupal::queue('strawberryfields_child_output');

unset($output);
$output[0] = $child_id;
$output[1] = $hocr_return;
$output[2] = $hOCR_json_output;
$childQueue_output->createItem(serialize($output));

//set return code 0=OK, other= error
drush_set_context('DRUSH_EXIT_CODE', $hocr_return);

//  apt install libtiff-tools pdf2djvu ocrodjvu qpdf
//
//    qpdf --empty --pages 4pag.pdf 1 -- 01pag.pdf
//
//		OLDIFS=$IFS
//		IFS=' '		WxH=($(tiffinfo ../tif/$sn.tif |grep Image))
//		IFS=$OLDIFS
//		TIFFSIZE=${WxH[2]}"x"${WxH[5]}
//		echo "TIFF SIZE: "$TIFFSIZE
//
//		pdf2djvu --media-box --no-metadata -j0 --page-size=$TIFFSIZE -o "$sn.djv" "$filepdf" 2> /dev/null
//
//    djvu2hocr $BOOKDIR"/pdfs/"$CFILE".djv" > $SHOCR
//
//    tesseract /mnt/archicantadata/4da/image-04.tiff stdout -l ita hocr
//




?>
