<?php

#ifndef KPHP
spl_autoload_register(function($class) {
  $rel_filename = trim(str_replace('\\', '/', $class), '/') . '.php';
  $filename = __DIR__ . '/..' . '/' . $rel_filename;
  if (file_exists($filename)) {
    require_once $filename;
  }
}, true, true);

// todo require kphp_polyfills or install them using Composer
// see https://github.com/VKCOM/kphp-polyfills
// (or don't do this, since job workers just don't work in plain PHP,
//  and in practice, you should provide local fallback; this demo is focused to be KPHP-only :)
require_once '/some/where/kphp-polyfills/kphp_polyfills.php';
#endif


class MyRequest extends \JobWorkers\JobWorkerSimple {
  /** @var false|string */
  public $session_id;

  /** @var array<mixed> */
  public $predefined_consts;

  /** @var mixed */
  public $session_array;

  /** @var bool */
  public $must_sleep;

  /** @var bool */
  public $must_call_gc;

  /**
   * @param array<mixed> $predefined_consts
   * @param mixed $session_array
   * @param false|string $id
   * @param bool $must_sleep
   * @param bool $must_call_gc
  */
  public function __construct($predefined_consts, $session_array, $id, $must_sleep, $must_call_gc) {
    $this->session_id = $id;
    $this->predefined_consts = $predefined_consts;
    $this->session_array = $session_array;
    $this->must_sleep = $must_sleep;
    $this->must_call_gc = $must_call_gc;
  }

  function handleRequest(): ?\KphpJobWorkerResponse {
    $response = new MyResponse();
    session_id($this->session_id);
    
    $response->start_time = microtime(true);
    session_start($this->predefined_consts);
    $response->session_status = (session_status() == 2);
    $response->end_time = microtime(true);
    
    $response->session_id = session_id();
    if (!$response->session_status) {
      return $response;
    }
    
    $session_array_before = unserialize(session_encode());
    session_decode(serialize(array_merge($session_array_before, $this->session_array)));
    // or: $_SESSION = array_merge($this->session_array, $_SESSION);
    $response->session_array = unserialize(session_encode());
    // or: $response->session_array = $_SESSION;

    if ($this->must_sleep) {
      usleep(4 * 100000);
    }

    if ($this->must_call_gc) {
      session_gc();
    }

    session_commit();
    
    return $response;
  }
}

class MyResponse implements \KphpJobWorkerResponse {
  /** @var float */
  public $start_time;

  /** @var float */
  public $end_time;

  /** @var bool */
  public $session_status;

  /** @var string|false */
  public $session_id;

  /** @var mixed */
  public $session_array;
}


if (PHP_SAPI !== 'cli' && isset($_SERVER["JOB_ID"])) {
  handleKphpJobWorkerRequest();
} else {
  handleHttpRequest();
}

function handleHttpRequest() {
  if (!\JobWorkers\JobLauncher::isEnabled()) {
    echo "JOB WORKERS DISABLED at server start, use -f 2 --job-workers-ratio 0.5", "\n";
    return;
  }

  $timeout = 4.5;
  $to_write = ["first_message" => "hello"];
  $session_params = ["gc_maxlifetime" => 1];

  $main_request = new MyRequest($session_params, $to_write, false, false, false);
  $main_job_id = \JobWorkers\JobLauncher::start($main_request, $timeout);

  $main_response = wait($main_job_id);
  $session_id = false;

  $is_response = ($main_response instanceof MyResponse);
  var_dump($is_response);

  if ($main_response instanceof MyResponse) {
    echo "\nCreated main session:\n";
    var_dump($main_response->session_status);
    var_dump($main_response->session_id);
    var_dump($main_response->session_array);
    $session_id = $main_response->session_id;

    var_dump($main_response->session_status);
    var_dump($main_response->session_array == $to_write);
  } else {
    return;
  }

  $session_params["gc_maxlifetime"] = 2000;
  $add_request = new MyRequest($session_params, ["add_message" => "welcome"], false, false, false);
  $add_job_id = \JobWorkers\JobLauncher::start($add_request, $timeout);

  $session_params["gc_maxlifetime"] = 1;
  $main_request = new MyRequest($session_params, ["second_message" => "world"], $session_id, true, false);
  $main_job_id = \JobWorkers\JobLauncher::start($main_request, $timeout);
  $main_2_request = new MyRequest($session_params, ["third_message" => "buy"], $session_id, false, false);
  $main_2_job_id = \JobWorkers\JobLauncher::start($main_2_request, $timeout);
  
  $new_request = new MyRequest($session_params, ["new_message" => "hi"], false, false, true);
  $new_job_id = \JobWorkers\JobLauncher::start($new_request, $timeout);

  // $add_response = wait($add_job_id);
  // $main_response = wait($main_job_id);
  // $main_2_response = wait($main_2_job_id);
  $new_response = wait($new_job_id);

  $s_files = scandir("/tmp/sessions/");
  var_dump($s_files);
  // if ($main_response instanceof MyResponse) {
  //   echo "\nOpened session:\n";
  //   var_dump($main_response->session_status);
  //   var_dump($main_response->session_id);
  //   var_dump($main_response->session_array);

  //   $to_write["second_message"] = "world";
  //   var_dump($main_response->session_status);
  //   var_dump($main_response->session_id == $session_id);
  //   var_dump($main_response->session_array == $to_write);
  //   var_dump(!in_array($main_response->session_id, $s_files));
  // }

  // if ($main_2_response instanceof MyResponse) {
  //   echo "\nOpened session:\n";
  //   var_dump($main_2_response->session_status);
  //   var_dump($main_2_response->session_id);
  //   var_dump($main_2_response->session_array);

  //   $to_write["third_message"] = "buy";
  //   var_dump($main_2_response->session_status);
  //   var_dump($main_2_response->session_id == $session_id);
  //   var_dump($main_2_response->session_array == $to_write);
  //   var_dump(!in_array($main_2_response->session_id, $s_files));
  // }

  // if ($add_response instanceof MyResponse) {
  //   echo "\nOpened session:\n";
  //   var_dump($add_response->session_status);
  //   var_dump($add_response->session_id);
  //   var_dump($add_response->session_array);

  //   var_dump($add_response->session_status);
  //   var_dump($add_response->session_id != $session_id);
  //   var_dump($add_response->session_array == ["add_message" => "welcome"]);
  //   var_dump(in_array($add_response->session_id, $s_files));
  // }

  if ($new_response instanceof MyResponse) {
    echo "\nOpened session:\n";
    var_dump($new_response->session_status);
    var_dump($new_response->session_id);
    var_dump($new_response->session_array);

    var_dump($new_response->session_status);
    var_dump($new_response->session_id != $session_id);
    var_dump($new_response->session_array == ["new_message" => "hi"]);
    var_dump(in_array($new_response->session_id, $s_files));
  }
}

function handleKphpJobWorkerRequest() {
  $kphp_job_request = kphp_job_worker_fetch_request();
  if (!$kphp_job_request) {
    warning("Couldn't fetch a job worker request");
    return;
  }

  if ($kphp_job_request instanceof \JobWorkers\JobWorkerSimple) {
    // simple jobs: they start, finish, and return the result
    $kphp_job_request->beforeHandle();
    $response = $kphp_job_request->handleRequest();
    if ($response === null) {
      warning("Job request handler returned null for " . get_class($kphp_job_request));
      return;
    }
    kphp_job_worker_store_response($response);

  } else if ($kphp_job_request instanceof \JobWorkers\JobWorkerManualRespond) {
    // more complicated jobs: they start, send a result in the middle (here get get it) — and continue working
    $kphp_job_request->beforeHandle();
    $kphp_job_request->handleRequest();
    if (!$kphp_job_request->wasResponded()) {
      warning("Job request handler didn't call respondAndContinueExecution() manually " . get_class($kphp_job_request));
    }

  } else if ($kphp_job_request instanceof \JobWorkers\JobWorkerNoReply) {
    // background jobs: they start and never send any result, just continue in the background and finish somewhen
    $kphp_job_request->beforeHandle();
    $kphp_job_request->handleRequest();

  } else {
    warning("Got unexpected job request class: " . get_class($kphp_job_request));
  }
}
