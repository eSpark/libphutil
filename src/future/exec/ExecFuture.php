<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Execute system commands in parallel using futures.
 *
 * ExecFuture is a future, which means it runs asynchronously and represents
 * a value which may not exist yet. See @{article:Using Futures} for an
 * explanation of futures. When an ExecFuture resolves, it returns the exit
 * code, stdout and stderr of the process it executed.
 *
 * ExecFuture is the core command execution implementation in libphutil, but is
 * exposed through a number of APIs. See @{article:Command Execution} for more
 * discussion about executing system commands.
 *
 * @task create   Creating ExecFutures
 * @task resolve  Resolving Execution
 * @task config   Configuring Execution
 * @task info     Command Information
 * @task interact Interacting With Commands
 * @task internal Internals
 * @group exec
 */
class ExecFuture extends Future {

  const TIMED_OUT_EXIT_CODE = 142;

  protected $pipes        = array();
  protected $proc         = null;
  protected $start        = null;
  protected $timeout      = null;
  protected $procStatus   = null;

  protected $stdout       = null;
  protected $stderr       = null;
  protected $stdin        = null;
  protected $closePipe    = false;

  protected $stdoutPos    = 0;
  protected $stderrPos    = 0;
  protected $command      = null;
  protected $cwd;

  protected $stdoutSizeLimit = PHP_INT_MAX;
  protected $stderrSizeLimit = PHP_INT_MAX;

  protected static $echoMode = array();
  protected static $descriptorSpec = array(
    0 => array('pipe', 'r'),  // stdin
    1 => array('pipe', 'w'),  // stdout
    2 => array('pipe', 'w'),  // stderr
  );

  public static function pushEchoMode($mode) {
    self::$echoMode[] = $mode;
  }

  public static function popEchoMode() {
    array_pop(self::$echoMode);
  }

  public static function peekEchoMode() {
    return end(self::$echoMode);
  }


/* -(  Creating ExecFutures  )----------------------------------------------- */


  /**
   * Create a new ExecFuture.
   *
   *   $future = new ExecFuture('wc -l %s', $file_path);
   *
   * @param string  ##sprintf()##-style command string which will be passed
   *                through @{function:csprintf} with the rest of the arguments.
   * @param ...     Zero or more additional arguments for @{function:csprintf}.
   * @return ExecFuture ExecFuture for running the specified command.
   * @task create
   */
  public function __construct($command) {
    $argv = func_get_args();
    $this->command = call_user_func_array('csprintf', $argv);
  }


/* -(  Configuring Execution  )---------------------------------------------- */


  /**
   * Retrieve the raw command to be executed.
   *
   * @return string Raw command.
   * @task info
   */
  public function getCommand() {
    return $this->command;
  }


  /**
   * Retrieve the byte limit for the stderr buffer.
   *
   * @return int Maximum buffer size, in bytes.
   * @task info
   */
  public function getStderrSizeLimit() {
    return $this->stderrSizeLimit;
  }


  /**
   * Retrieve the byte limit for the stdout buffer.
   *
   * @return int Maximum buffer size, in bytes.
   * @task info
   */
  public function getStdoutSizeLimit() {
    return $this->stdoutSizeLimit;
  }


  /**
   * Get the process's pid. This only works after execution is initiated, e.g.
   * by a call to start().
   *
   * @return int Process ID of the executing process.
   * @task info
   */
  public function getPID() {
    $status = $this->procGetStatus();
    return $status['pid'];
  }


/* -(  Configuring Execution  )---------------------------------------------- */


  /**
   * Set a maximum size for the stdout read buffer. To limit stderr, see
   * @{method:setStderrSizeLimit}. The major use of these methods is to use less
   * memory if you are running a command which sometimes produces huge volumes
   * of output that you don't really care about.
   *
   * NOTE: Setting this to 0 means "no buffer", not "unlimited buffer".
   *
   * @param int Maximum size of the stdout read buffer.
   * @return this
   * @task config
   */
  public function setStdoutSizeLimit($limit) {
    $this->stdoutSizeLimit = $limit;
  }

  /**
   * Set a maximum size for the stderr read buffer.
   * See @{method:setStdoutSizeLimit} for discussion.
   *
   * @param int Maximum size of the stderr read buffer.
   * @return this
   * @task config
   */
  public function setStderrSizeLimit($limit) {
    $this->stderrSizeLimit = $limit;
    return $this;
  }

  /**
   * Set the current working directory to use when executing the command.
   *
   * @param string Directory to set as CWD before executing the command.
   * @return this
   * @task config
   */
  public function setCWD($cwd) {
    $this->cwd = $cwd;
    return $this;
  }


/* -(  Interacting With Commands  )------------------------------------------ */


  /**
   * Read and return output from stdout and stderr, if any is available. This
   * method keeps a read cursor on each stream, but the entire streams are
   * still returned when the future resolves. You can call read() again after
   * resolving the future to retrieve only the parts of the streams you did not
   * previously read:
   *
   *   $future = new ExecFuture('...');
   *   // ...
   *   list($stdout) = $future->read(); // Returns output so far
   *   list($stdout) = $future->read(); // Returns new output since first call
   *   // ...
   *   list($stdout) = $future->resolvex(); // Returns ALL output
   *   list($stdout) = $future->read(); // Returns unread output
   *
   * NOTE: If you set a limit with @{method:setStdoutSizeLimit} or
   * @{method:setStderrSizeLimit}, this method will not be able to read data
   * past the limit.
   *
   * NOTE: If you call @{method:discardBuffers}, all the stdout/stderr data
   * will be thrown away and the cursors will be reset.
   *
   * @return pair <$stdout, $stderr> pair with new output since the last call
   *              to this method.
   * @task interact
   */
  public function read() {
    if ($this->start) {
      $this->isReady(); // Sync
    }

    $result = array(
      (string)substr($this->stdout, $this->stdoutPos),
      (string)substr($this->stderr, $this->stderrPos),
    );

    $this->stdoutPos = strlen($this->stdout);
    $this->stderrPos = strlen($this->stderr);

    return $result;
  }


  /**
   * Write data to stdin of the command.
   *
   * @param string Data to write.
   * @param bool If true, keep the pipe open for writing. By default, the pipe
   *             will be closed after the write completes so that commands which
   *             listen for EOF will execute.
   * @return this
   * @task interact
   */
  public function write($data, $keep_pipe = false) {
    $this->stdin .= $data;

    if (!$keep_pipe) {
      $this->closePipe = true;
    }

    if ($this->start) {
      $this->isReady(); // Sync
    }

    return $this;
  }


  /**
   * Permanently discard the stdout and stderr buffers and reset the read
   * cursors. This is basically useful only if you are streaming a large amount
   * of data from some process:
   *
   *   $future = new ExecFuture('zcat huge_file.gz');
   *   do {
   *     $done = $future->resolve(0.1);   // Every 100ms,
   *     list($stdout) = $future->read(); // read output...
   *     echo $stdout;                    // send it somewhere...
   *     $future->discardBuffers();       // and then free the buffers.
   *   } while ($done === null);
   *
   * Conceivably you might also need to do this if you're writing a client using
   * ExecFuture and ##netcat##, but you probably should not do that.
   *
   * NOTE: This completely discards the data. It won't be available when the
   * future resolves. This is almost certainly only useful if you need the
   * buffer memory for some reason.
   *
   * @return this
   * @task interact
   */
  public function discardBuffers() {
    $this->stdout = '';
    $this->stderr = '';
    $this->stdoutPos = 0;
    $this->stderrPos = 0;

    return $this;
  }


/* -(  Configuring Execution  )---------------------------------------------- */


  /**
   * Set a hard limit on execution time. If the command runs longer, it will
   * be killed and the future will resolve with error code
   * ##ExecFuture::TIMED_OUT_EXIT_CODE##.
   *
   * @param int Maximum number of seconds this command may execute for.
   * @return this
   * @task config
   */
  public function setTimeout($seconds) {
    $this->timeout = $seconds;
    return $this;
  }


/* -(  Resolving Execution  )------------------------------------------------ */


  /**
   * Resolve a command, returning its exit code, stdout, and stderr. See also
   * @{function:exec_manual}. For stronger error-checking behavior, see
   * @{method:resolvex} and @{method:resolveJSON}.
   *
   *   list($err, $stdout, $stderr) = $future->resolve();
   *
   * @param  float Optional timeout after which resolution will pause and
   *               execution will return to the caller.
   * @return list  <$err, $stdout, $stderr> list.
   * @task resolve
   */
  public function resolve($timeout = null) {
    if (null === $timeout) {
      $timeout = $this->timeout;
    }
    return parent::resolve($timeout);
  }


  /**
   * Resolve a command you expect to exit with return code 0. Works like
   * @{method:resolve}, but throws if $err is nonempty. Returns only
   * $stdout and $stderr. See also @{function:execx}.
   *
   *   list($stdout, $stderr) = $future->resolvex();
   *
   * @param  float Optional timeout after which resolution will pause and
   *               execution will return to the caller.
   * @return pair  <$stdout, $stderr> pair.
   * @task resolve
   */
  public function resolvex($timeout = null) {
    list($err, $stdout, $stderr) = $this->resolve($timeout);
    if ($err) {
      $cmd = $this->command;
      throw new CommandException(
        "Command '{$cmd}' failed with error #{$err}:\n".
        "stdout:\n{$stdout}\n".
        "stderr:\n{$stderr}\n",
        $cmd,
        $err,
        $stdout,
        $stderr);
    }
    return array($stdout, $stderr);
  }


  /**
   * Resolve a command you expect to return valid JSON. Works like
   * @{method:resolvex}, but also throws if stderr is nonempty, or stdout is not
   * valid JSON. Returns a PHP array, decoded from the JSON command output.
   *
   * @param  float Optional timeout after which resolution will pause and
   *               execution will return to the caller.
   * @return array PHP array, decoded from JSON command output.
   * @task resolve
   */
  public function resolveJSON($timeout = null) {
    list($stdout, $stderr) = $this->resolvex($timeout);
    if (strlen($stderr)) {
      $cmd = $this->command;
      throw new CommandException(
        "JSON command '{$cmd}' emitted text to stderr when none was expected: ".
        $stderr,
        $cmd,
        0,
        $stdout,
        $stderr);
    }
    $object = json_decode($stdout, true);
    if (!is_array($object)) {
      $cmd = $this->command;
      throw new CommandException(
        "JSON command '{$cmd}' did not produce a valid JSON object on stdout: ".
        $stdout,
        $cmd,
        0,
        $stdout,
        $stderr);
    }
    return $object;
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * Provides read sockets to the future core.
   *
   * @return list List of read sockets.
   * @task internal
   */
  public function getReadSockets() {
    list($stdin, $stdout, $stderr) = $this->pipes;
    $sockets = array();
    if (isset($stdout) && !feof($stdout)) {
      $sockets[] = $stdout;
    }
    if (isset($stderr) && !feof($stderr)) {
      $sockets[] = $stderr;
    }
    return $sockets;
  }


  /**
   * Provides write sockets to the future core.
   *
   * @return list List of write sockets.
   * @task internal
   */
  public function getWriteSockets() {
    list($stdin, $stdout, $stderr) = $this->pipes;
    $sockets = array();
    if (isset($stdin) && strlen($this->stdin) && !feof($stdin)) {
      $sockets[] = $stdin;
    }
    return $sockets;
  }


  /**
   * Reads some bytes from a stream, discarding output once a certain amount
   * has been accumulated.
   *
   * @param resource  Stream to read from.
   * @param int       Maximum number of bytes to return from $stream. If
   *                  additional bytes are available, they will be read and
   *                  discarded.
   * @param string    Human-readable description of stream, for exception
   *                  message.
   * @return string   The data read from the stream.
   * @task internal
   */
  protected function readAndDiscard($stream, $limit, $description) {
    $output = '';

    do {
      $data = fread($stream, 4096);
      if (false === $data) {
        throw new Exception('Failed to read from '.$description);
      }

      $read_bytes = strlen($data);

      if ($read_bytes > 0 && $limit > 0) {
        if ($read_bytes > $limit) {
          $data = substr($data, 0, $limit);
        }
        $output .= $data;
        $limit -= strlen($data);
      }
    } while ($read_bytes > 0);

    return $output;
  }


  /**
   * Begin or continue command execution.
   *
   * @return bool True if future has resolved.
   * @task internal
   */
  public function isReady() {

    if (!$this->pipes) {

      if (self::peekEchoMode()) {
        echo "  >>> \$ {$this->command}\n";
      }

      $pipes = array();
      $proc = proc_open(
        $this->command,
        self::$descriptorSpec,
        $pipes,
        $this->cwd);
      if (!is_resource($proc)) {
        throw new Exception('Failed to open process.');
      }

      $this->start = time();
      $this->pipes = $pipes;
      $this->proc  = $proc;

      list($stdin, $stdout, $stderr) = $pipes;

      if ((!stream_set_blocking($stdout, false)) ||
          (!stream_set_blocking($stderr, false)) ||
          (!stream_set_blocking($stdin,  false))) {
        $this->__destruct();
        throw new Exception('Failed to set streams nonblocking.');
      }

      return false;
    }

    if (!$this->proc) {
      return true;
    }

    list($stdin, $stdout, $stderr) = $this->pipes;

    if (isset($this->stdin) && strlen($this->stdin)) {
      $bytes = fwrite($stdin, $this->stdin);
      if ($bytes === false) {
        throw new Exception('Unable to write to stdin!');
      } else if ($bytes) {
        $this->stdin = substr($this->stdin, $bytes);
      }

      if (!strlen($this->stdin) && $this->closePipe) {
        @fclose($stdin);
        $this->pipes[0] = null;
      }
    } else {
      if ($this->closePipe && $stdin) {
        @fclose($stdin);
      }
      $this->pipes[0] = null;
    }

    //  Read status before reading pipes so that we can never miss data that
    //  arrives between our last read and the process exiting.
    $status = $this->procGetStatus();

    $this->stdout .= $this->readAndDiscard(
      $stdout,
      $this->getStdoutSizeLimit() - strlen($this->stdout),
      'stdout');
    $this->stderr .= $this->readAndDiscard(
      $stderr,
      $this->getStderrSizeLimit() - strlen($this->stderr),
      'stderr');

    if (!$status['running']) {
      $this->result = array(
        $status['exitcode'],
        $this->stdout,
        $this->stderr,
      );
      $this->__destruct();
      return true;
    }

    if ($this->timeout && ((time() - $this->start) >= $this->timeout)) {
      if (defined('SIGKILL')) {
        $signal = SIGKILL;
      } else {
        $signal = 9;
      }
      proc_terminate($this->proc, $signal);
      $this->result = array(
        self::TIMED_OUT_EXIT_CODE,
        $this->stdout,
        $this->stderr."\n".
        "(This process was prematurely terminated by timeout.)");
     $this->__destruct();
     return true;
    }

  }


  /**
   * Close and free resources if necessary.
   *
   * @return void
   * @task internal
   */
  public function __destruct() {
    foreach ($this->pipes as $pipe) {
      if (isset($pipe)) {
        @fclose($pipe);
      }
    }
    $this->pipes  = array(null, null, null);
    if ($this->proc) {
      @proc_close($this->proc);
      $this->proc = null;
    }
    $this->stdin  = null;
  }

  /**
   * Execute proc_get_status(), but avoid pitfalls.
   *
   * @return dict Process status.
   * @task internal
   */
  private function procGetStatus() {
    // After the process exits, we only get one chance to read proc_get_status()
    // before it starts returning garbage. Make sure we don't throw away the
    // last good read.
    if ($this->procStatus) {
      if (!$this->procStatus['running']) {
        return $this->procStatus;
      }
    }
    $this->procStatus = proc_get_status($this->proc);
    return $this->procStatus;
  }

}
