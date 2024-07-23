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

  /** @var string */
  public $title;

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
   * @param string $title
   * @param bool $must_sleep
   * @param bool $must_call_gc
  */
  public function __construct($predefined_consts, $session_array, $id, $title, $must_sleep, $must_call_gc) {
    $this->session_id = $id;
    $this->title = $title;
    $this->predefined_consts = $predefined_consts;
    $this->session_array = $session_array;
    $this->must_sleep = $must_sleep;
    $this->must_call_gc = $must_call_gc;
  }

  function handleRequest(): ?\KphpJobWorkerResponse {
    $response = new MyResponse();
    $response->title = $this->title;
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
      sleep(4);
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

  /** @var string */
  public $title;

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
  $session_params = ["save_path" => "/tmp/sessions", "gc_maxlifetime" => 2000];

  $main_request = new MyRequest($session_params, $to_write, false, "main", false, false);
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

  $futures_array = [];

  $session_params["gc_maxlifetime"] = 2000;
  $add_request = new MyRequest($session_params, ["add_message" => "welcome"], false, "add", false, false);
  $job_id = \JobWorkers\JobLauncher::start($add_request, $timeout);
  if ($job_id !== false) {
    $futures_array[] = $job_id;
  }

  $session_params["gc_maxlifetime"] = 1;
  $main_request = new MyRequest($session_params, ["second_message" => "world"], $session_id, "main", true, false);
  $job_id = \JobWorkers\JobLauncher::start($main_request, $timeout);
  if ($job_id !== false) {
    $futures_array[] = $job_id;
  }
  
  $main_2_request = new MyRequest($session_params, ["third_message" => "buy"], $session_id, "main_2", false, false);
  $job_id = \JobWorkers\JobLauncher::start($main_2_request, $timeout);
  if ($job_id !== false) {
    $futures_array[] = $job_id;
  }
  
  $new_request = new MyRequest($session_params, ["new_message" => "hi"], false, "new", false, true);
  $job_id = \JobWorkers\JobLauncher::start($new_request, $timeout);
  if ($job_id !== false) {
    $futures_array[] = $job_id;
  }

  $responses = wait_multi($futures_array);
  $s_files = scandir($session_params["save_path"]);
  var_dump($s_files);

  foreach ($responses as $response) {
    if ($response instanceof MyResponse) {
      var_dump($response->session_status);
      var_dump($response->title);
      var_dump($response->session_id);
      var_dump($response->session_array);
    }
  }

  $conflicted_filename = $session_params["save_path"] . $session_id;
  var_dump(file_get_contents($conflicted_filename));
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
