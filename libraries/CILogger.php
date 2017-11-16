<?php
if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class CILogger {
  var $log_frequency;
  var $CI;
  /**
   * Constructor.
   */
  function __construct() {
      $this->CI =& get_instance();
      if (!is_null($this->CI)) {
          // Load configuration
          $this->config->load('fms_endpoint', FALSE, TRUE);
          $this->log_frequency = $this->config->item('log_frequency');
          $this->db_name = $this->db->database;
      }
  }

  /**
   * Sets a benchmark point before the application code runs.
   */
  function pre_application() {
      $this->CI->benchmark->mark('pre_controller');
  }

  /**
   * Records the total amount of time taken for the application code.
   */
  function post_application() {
      $this->CI->benchmark->mark('post_controller');
  }
  /**
   * Sets the log frequency.
   *
   * @param integer $new_frequency
   */
  function set_frequency($new_frequency) {
      assert('is_long($new_frequency)');

      $this->log_frequency = $new_frequency;
  }
  /**
 * If we are logging this run, write profiling results to DB.
 */
function resolve_profiling() {
    // If we should log this run
    if (rand(0, 99) < $this->log_frequency) {
        // Total consumed memory
        $memory = ( ! function_exists('memory_get_usage')) ? 0 : memory_get_usage();

        // Total elapsed script time
        $render_elapsed = $this->CI->benchmark->elapsed_time('total_execution_time_start', 'total_execution_time_end');

        // Controller elapsed script time
        $controller_elapsed = $this->CI->benchmark->elapsed_time('pre_controller', 'post_controller');

        // CodeIgniter elapsed script time
        $ci_elapsed = $render_elapsed - $controller_elapsed;

        // DB calls
        if ( ! class_exists('CI_DB_driver')) {
            $mysql_count_queries    = 0;
            $mysql_queries          = '';
            $mysql_elapsed          = 0;
        }
        else {
            if (count($this->CI->db->queries) == 0) {
                $mysql_count_queries    = 0;
                $mysql_queries          = '';
                $mysql_elapsed          = 0;
            }
            else {
                $query_accum = 0;
                $aq = array();
                $first = true;

                foreach ($this->CI->db->queries as $key => $val) {
                    // Get and accumulate time for this query
                    $time = number_format($this->CI->db->query_times[$key], 4);
                    $query_accum += $this->CI->db->query_times[$key];

                    // Get and accumulate actual queries
                    $val = str_replace("\n" , ' ', htmlspecialchars($val, ENT_QUOTES));
                    $aq[] = $first ? 'Query: ' : "\nQuery: ";
                    $aq[] = $val;
                    $aq[] = "\nTime:  ";
                    $aq[] = $time;

                    $first = false;
                }

                $mysql_count_queries    = count($this->CI->db->queries);
                $mysql_queries          = implode('', $aq);
                $mysql_elapsed          = number_format($query_accum, 4);
            }
        }
        // Prepare insert
        $this->CI->load->database();
        //$table_name = $this->db_name . '.performance_log_' . @date('ymd');
        $table_name = $this->db_name . '.ci_logs';
        $sql = "INSERT DELAYED INTO $table_name (
                ip,
                page,
                user_agent,
                referrer,
                memory,
                render_elapsed,
                ci_elapsed,
                controller_elapsed,
                mysql_count_queries,
                mysql_queries,
                mysql_elapsed
            ) VALUES (
                '" . ip2long(@$_SERVER['REMOTE_ADDR']) . "',
                '" . @$_SERVER['REQUEST_URI'] . "',
                '" . @$_SERVER['HTTP_USER_AGENT'] . "',
                '" . @$_SERVER['HTTP_REFERER'] . "',
                '$memory',
                '$render_elapsed',
                '$ci_elapsed',
                '$controller_elapsed',
                '$mysql_count_queries',
                '$mysql_queries',
                '$mysql_elapsed'
            );";

        // Turn off DB debug mode
        $saved_debug = $this->CI->db->db_debug;
        $this->CI->db->db_debug = false;

        // Attempt query
        $this->CI->db->query($sql);

        // Restore DB debug mode
        $this->CI->db->db_debug = $saved_debug;

        // Handle "Table Not Found" error
        if (1146 === $this->CI->db->_error_number()) {
            // Create new table for each new day
            $this->CI->db->query('CREATE TABLE ' . $table_name . ' LIKE ' . $this->db_name . '.ci_logs');

            // Insert again
            $this->CI->db->query($sql);
        }
    }
  }
}
?>
