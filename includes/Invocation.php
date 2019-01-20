<?php
/**
 * Invocation Class.
 *
 * @package   Google\WP_Sourcery
 * @link      https://github.com/westonruter/wp-sourcery
 * @license   GPL-2.0-or-later
 * @copyright 2019 Google Inc.
 */

namespace Google\WP_Sourcery;

/**
 * Class Invocation.
 *
 * @todo Subclass for hooks, shortcodes, widgets, embeds, and blocks.
 */
class Invocation {

	/**
	 * Number of instances.
	 *
	 * @var int
	 */
	protected static $instance_count = 0;

	/**
	 * ID.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Invocation Watcher.
	 *
	 * @var Invocation_Watcher
	 */
	public $invocation_watcher;

	/**
	 * Instance of File_Locator.
	 *
	 * @var File_Locator
	 */
	public $file_locator;

	/**
	 * Instance of Database.
	 *
	 * @var Database
	 */
	public $database;

	/**
	 * Instance of Dependencies.
	 *
	 * @var Dependencies
	 */
	public $dependencies;

	/**
	 * Parent invocation.
	 *
	 * @var Invocation
	 */
	public $parent;

	/**
	 * Children invocations.
	 *
	 * @var Invocation[]
	 */
	public $children = [];

	/**
	 * Callback function.
	 *
	 * @var callable
	 */
	public $function;

	/**
	 * Nice name of the callback function.
	 *
	 * @var string
	 */
	public $function_name;

	/**
	 * Reflection object for the function.
	 *
	 * @var \ReflectionMethod|\ReflectionFunction
	 */
	public $reflection;

	/**
	 * File in which the function was defined.
	 *
	 * @var string
	 */
	public $source_file;

	/**
	 * Start time.
	 *
	 * @var float
	 */
	public $start_time;

	/**
	 * End time.
	 *
	 * @var float
	 */
	public $end_time;

	/**
	 * Whether the invocation happened inside of a start tag (e.g. in its attributes).
	 *
	 * @see Invocation_Watcher::purge_hook_annotations_in_start_tag()
	 * @var bool
	 */
	public $intra_tag = false;

	/**
	 * Number of queries before function called.
	 *
	 * @var int
	 */
	protected $before_num_queries;

	/**
	 * Script handles that were enqueued prior to running the hook callback.
	 *
	 * This is unset when the invocation is finalized.
	 *
	 * @var string[]
	 */
	protected $before_scripts_queue;

	/**
	 * Scripts enqueued during invocation of hook callback.
	 *
	 * @todo Put into a multi-dimensional enqueued_dependencies array?
	 * @var string[]
	 */
	public $enqueued_scripts;

	/**
	 * Style handles that were enqueued prior to running the hook callback.
	 *
	 * This is unset when the invocation is finalized.
	 *
	 * @var string[]
	 */
	protected $before_styles_queue;

	/**
	 * Styles enqueued during invocation of hook callback.
	 *
	 * @todo Before finalized, this could return the current array_diff( wp_styles()->queue, $before_styles_queue ) or call identify_enqueued_styles? Would not be final, however.
	 * @var string[]
	 */
	public $enqueued_styles;

	/**
	 * The indices of the queries in $wpdb->queries that this hook was responsible for.
	 *
	 * @var int[]
	 */
	public $query_indices;

	/**
	 * Constructor.
	 *
	 * @param Invocation_Watcher $watcher      Watcher.
	 * @param Database           $database     Database.
	 * @param File_Locator       $file_locator File locator.
	 * @param Dependencies       $dependencies Dependencies.
	 * @param array              $args    Arguments which are assigned to properties.
	 */
	public function __construct( Invocation_Watcher $watcher, Database $database, File_Locator $file_locator, Dependencies $dependencies, $args ) {
		foreach ( $args as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
		$this->id                 = ++static::$instance_count;
		$this->invocation_watcher = $watcher;
		$this->database           = $database;
		$this->file_locator       = $file_locator;
		$this->dependencies       = $dependencies;
		$this->start_time         = microtime( true );

		$this->before_num_queries = $this->database->get_wpdb()->num_queries;

		// @todo Better to have some multi-dimensional array structure here?
		$this->before_scripts_queue = $this->dependencies->get_dependency_queue( 'wp_scripts' );
		$this->before_styles_queue  = $this->dependencies->get_dependency_queue( 'wp_styles' );
	}

	/**
	 * Whether this invocation is expected to produce output.
	 *
	 * @return bool Whether output is expected.
	 */
	public function can_output() {
		return true;
	}

	/**
	 * Get the query count before the hook was invoked.
	 *
	 * @return int Query count.
	 */
	public function get_before_num_queries() {
		return $this->before_num_queries;
	}

	/**
	 * Get the script handles enqueued before the hook callback was invoked.
	 *
	 * @todo Combine into get_before_dependencies_queue?
	 * @return string[] Script handles.
	 */
	public function get_before_scripts_queue() {
		return $this->before_scripts_queue;
	}

	/**
	 * Get the style handles enqueued before the hook callback was invoked.
	 *
	 * @return string[] Style handles.
	 */
	public function get_before_styles_queue() {
		return $this->before_styles_queue;
	}

	/**
	 * Finalize the invocation.
	 */
	public function finalize() {
		$this->end_time = microtime( true );

		// Flag the queries that were used during this hook.
		$this->query_indices = $this->database->identify_invocation_queries( $this );

		// Capture the scripts and styles that were enqueued by this hook.
		$this->enqueued_scripts = $this->dependencies->identify_enqueued_scripts( $this );
		$this->enqueued_styles  = $this->dependencies->identify_enqueued_styles( $this );

		// These are no longer needed after calling identify_queued_scripts and identify_queued_styles, and they just take up memory.
		unset( $this->before_scripts_queue );
		unset( $this->before_styles_queue );
	}

	/**
	 * Get the duration of the hook callback invocation.
	 *
	 * @param bool $own_time Whether to exclude children invocations from the total time.
	 * @return float Duration.
	 */
	public function duration( $own_time = false ) {
		// The end_time won't be set if method is invoked before finalize() is called.
		$end_time = isset( $this->end_time ) ? $this->end_time : microtime( true );

		$duration = $end_time - $this->start_time;

		if ( $own_time ) {
			foreach ( $this->children as $invocation ) {
				$duration -= $invocation->duration( false );
			}
		}
		return $duration;
	}

	/**
	 * Get the queries made during the hook callback invocation.
	 *
	 * @return array|null Queries or null if no queries are being saved (SAVEQUERIES).
	 */
	public function queries() {
		$wpdb = $this->database->get_wpdb();
		if ( empty( $wpdb->queries ) || ! isset( $this->query_indices ) ) {
			return null;
		}

		$search = sprintf( '%1$s->%2$s\{closure}, call_user_func_array, ', Hook_Wrapper::class, __NAMESPACE__ );

		$queries = array_map(
			function ( $query_index ) use ( $wpdb, $search ) {
				$query = $wpdb->queries[ $query_index ];

				// Purge references to the hook wrapper from the query call stack.
				$backtrace = explode( ', ', str_replace( $search, '', $query[2] ) );

				return [
					'sql'       => $query[0],
					'duration'  => floatval( $query[1] ),
					'backtrace' => $backtrace,
					'timestamp' => floatval( $query[2] ),
				];
			},
			$this->query_indices
		);

		return $queries;
	}

	/**
	 * Get the location of the file.
	 *
	 * @return array|null {
	 *     Location information, or null if no location could be identified.
	 *
	 *     @var string               $type The type of location, either core, plugin, mu-plugin, or theme.
	 *     @var string               $name The name of the entity, such as 'twentyseventeen' or 'amp/amp.php'.
	 *     @var \WP_Theme|array|null $data Additional data about the entity, such as the theme object or plugin data.
	 * }
	 */
	public function file_location() {
		return $this->file_locator->identify( $this->source_file );
	}

	/**
	 * Get data for exporting.
	 *
	 * @return array Data.
	 */
	public function data() {
		$data = [
			'id'       => $this->id,
			'function' => $this->function_name,
			'duration' => $this->duration(),
			'source'   => [
				'file' => $this->source_file,
			],
			'parent'   => $this->parent ? $this->parent->id : null,
			'children' => array_map(
				function( Invocation $invocation ) {
					return $invocation->id;
				},
				$this->children
			),
		];

		if ( $this->invocation_watcher->can_show_queries() ) {
			$queries = $this->queries();
			if ( ! empty( $queries ) ) {
				$data['queries'] = $queries;
			}
		}

		if ( ! empty( $this->enqueued_scripts ) ) {
			$data['enqueued_scripts'] = $this->enqueued_scripts;
		}
		if ( ! empty( $this->enqueued_styles ) ) {
			$data['enqueued_styles'] = $this->enqueued_styles;
		}

		$file_location = $this->file_location();
		if ( $file_location ) {
			$data['source']['type'] = $file_location['type'];
			$data['source']['name'] = $file_location['name'];
		}

		return $data;
	}
}
