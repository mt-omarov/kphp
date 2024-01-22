@ok
<?php

require_once 'kphp_tester_include.php';

function test_file_option() {
  $c = curl_init();
  $f = fopen("test_file_option", "w+");

  var_dump(curl_setopt($c, CURLOPT_FILE, $f));
  fclose($f);

  // bad option
  var_dump(curl_setopt($c, CURLOPT_FILE, null));
  $f = fopen("test_file_option", "r");
  var_dump(curl_setopt($c, CURLOPT_FILE, $f));
  fclose($f);

  curl_close($c);
  exec("rm ./test_file_option");
}

function test_infile_option() {
  $c = curl_init();
  exec("touch test_infile_option");
  $f = fopen("test_infile_option", "r");

  var_dump(curl_setopt($c, CURLOPT_INFILE, $f));
  fclose($f);
  exec("rm ./test_infile_option");

  // bad option
  var_dump(curl_setopt($c, CURLOPT_INFILE, null));
  
  curl_close($c);
}

function test_writeheader_option() {
  $c = curl_init();
  $f = fopen("test_writeheader_option", "w+");

  var_dump(curl_setopt($c, CURLOPT_WRITEHEADER, $f));
  fclose($f);

  // bad option
  var_dump(curl_setopt($c, CURLOPT_WRITEHEADER, null));
  $f = fopen("test_writeheader_option", "r");
  var_dump(curl_setopt($c, CURLOPT_WRITEHEADER, $f));

  exec("rm ./test_writeheader_option");
  curl_close($c);
}

test_file_option();
test_infile_option();
test_writeheader_option();

