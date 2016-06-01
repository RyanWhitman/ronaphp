<?php

class Rona {

	private static $instance;

	private
		$was_initialized = false,
		$autoloaders = [];
	
	private function __construct() {}
	private function __clone() {}
	private function __wakeup() {}

	private static function instance() {

		if (self::$instance == NULL)
			self::$instance = new self();

		return self::$instance;
	}

	public static function init() {

		if (!self::instance()->was_initialized) {

			// Load class files
				require_once __DIR__ . '/Config.php';
				require_once __DIR__ . '/Helper.php';
				require_once __DIR__ . '/Response.php';

			// Default configuration
				Config::set('rona')
					->_('debug_mode', true)
					->_('system_path', '')
					->_('system_dir', dirname(__DIR__))
					->_('core_dir', __DIR__)
					->_('request_uri', $_SERVER['REQUEST_URI'])
					->_('http_methods', ['get', 'post', 'put', 'patch', 'delete', 'options']);

				Config::set('rona.api')
					->_('paths', [])
					->_('locations')
						->_('config', '/api/config.php')
						->_('routes', '/api/routes.php')
						->_('filters', '/api/filters')
						->_('procedures', '/api/procedures');

				Config::set('rona.api.hooks')
					->_('onAuthentication_failure', function($res) {

						echo json_encode($res);
						return false;
					})
					->_('onParam_failure', function($res) {

						echo json_encode($res);
						return false;
					})
					->_('onAuthorization_failure', function($res) {

						echo json_encode($res);
						return false;
					})
					->_('onSuccess', function($res) {

						echo json_encode($res);
						return true;
					});

				Config::set('rona.api.authentication')
					->_('inject_query_string', false)
					->_('procedure', '')
					->_('header_params', []);

				Config::set('rona.app')
					->_('tmp_storage', '/cgi-bin/tmp')
					->_('locations')
						->_('config', '/app/config.php')
						->_('routes', '/app/routes.php')
						->_('controllers', '/app/controllers')
						->_('views', '/app/views');					

			// Load the general config file
				require_once Config::get('rona.system_dir') . '/config.php';

			// Error handling
				$is_debug_mode = Config::get('rona.debug_mode');
				# Checking for a boolean gives the developer the ability to skip this functionality
				if (is_bool($is_debug_mode)) {
					if ($is_debug_mode) {
						ini_set('display_errors', 1);
						ini_set('display_startup_errors', 1);
						error_reporting(-1);
					} else {
						ini_set('display_errors', 0);
						ini_set('display_startup_errors', 0);
						error_reporting(0);
					}
				}

			// Register autoloader
				spl_autoload_register(function($class) {

					foreach (self::instance()->autoloaders as $autoloader)
						if (is_callable($autoloader) && $autoloader($class))
							return true;
				});

			// Rona has been initialized
				self::instance()->was_initialized = true;
		}
	}

	public static function autoload_register($function) {
		self::instance()->autoloaders[] = $function;
	}
	
	public static function run() {

		// Initialize Rona
		self::init();

		// Establish http method. If "_http_method" override was posted, use it. Otherwise, use default
		require_once Config::get('rona.core_dir') . '/Request.php';
		Request::set('http_method', strtolower(!empty($_POST['_http_method']) ? $_POST['_http_method'] : $_SERVER['REQUEST_METHOD']));

		// Establish requested route
		$route_requested = str_replace(Config::get('rona.system_path'), '', Config::get('rona.request_uri'));
		$route_requested = strtok($route_requested, '?');
		if ($route_requested == '/')
			$route_requested = '';
		
		//$route_requested = ltrim($route_requested, '/');
		Request::set('route', $route_requested);

		// Is this an API route or an App route?
		$is_api = false;
		$api_paths = (array) Config::get('rona.api.paths');
		foreach ($api_paths as $api_path) {
			//$api_path = trim($api_path, '/');
			if ($api_path === '' || $api_path == Request::route() || strpos(Request::route(), $api_path/* . '/'*/) === 0) {
				$is_api = true;
				break;
			}
		}

		// Load the appropriate resources / configuration
		require_once Config::get('rona.core_dir') . '/Route.php';
		if ($is_api) {
			require_once Config::get('rona.system_dir') . Config::get('rona.api.locations.config');
			require_once Config::get('rona.core_dir') . '/Api.php';
			require_once Config::get('rona.system_dir') . Config::get('rona.api.locations.routes');
			header('Content-Type: application/json');
		} else {
			require_once Config::get('rona.system_dir') . Config::get('rona.app.locations.config');
			require_once Config::get('rona.core_dir') . '/App.php';
			require_once Config::get('rona.system_dir') . Config::get('rona.app.locations.routes');
		}

		// Turn the requested route into an array & get the count
		$route_requested_arr = explode('/', Request::route());
		$route_requested_count = count($route_requested_arr);
			
		// Establish an empty $route_found variable
		$route_found = '';
			
		// First attempt to find a direct match. If that fails, try matching a route with a variable in it.
		$direct_match = Helper::array_get(Route::get_routes(), Request::http_method() . '.regular.' . Request::route(), NULL);
		if (!is_null($direct_match))
			$route_found = $direct_match;
		else {

			$variable_matches = Helper::array_get(Route::get_routes(), Request::http_method() . '.variable', []);
			foreach ($variable_matches as $path => $components) {
				
				// Reset route_var array
					$route_vars = [];
				
				// Explode the route being examined into an array
					$route_examining_arr = explode('/', $path);
				
				// Ensure the arrays are the same size
					if ($route_requested_count == count($route_examining_arr)) {
					
						// Iterate thru each of the array elements. The requested route and the route being examined either need to match exactly or the route being examined needs to have a variable.
							$matches_needed = $route_requested_count;
							$matches_found = 0;
							for ($i = 0; $i < $matches_needed; $i++) {
								
								if ($route_requested_arr[$i] == $route_examining_arr[$i]) {
								
									// An exact match was found, so we'll continue to the next array item.
										$matches_found++;
										
								} else if (preg_match('/^{.+}$/', $route_examining_arr[$i])) {
								
									// The route being examined has a route variable, so it's a match. Set route_var array for use later on.
										$route_vars[str_replace(array('{', '}'), '', $route_examining_arr[$i])] = $route_requested_arr[$i];
										$matches_found++;
										
								} else {
								
									// A match was not found, so the route being examined isn't a match.
										break;
								}
							}
							
						if ($matches_found == $matches_needed) {
							$route_found = $components;
							break;
						}
					}
			}
		}
		
		// If $route_found is empty, load no_route
		if (empty($route_found)) {
			http_response_code(404);

			if ($is_api) {
				echo json_encode(Api::get_no_route());
				return;
			}

			$route_found = App::get_no_route();
		} else
			http_response_code(200);
			
		// Set the current route_vars
		if (!empty($route_vars))
			Request::set('route_vars', $route_vars);

		// Load Procedure class
		require_once Config::get('rona.core_dir') . '/Procedure.php';

		// Run the API, if applicable
		if ($is_api) {

			// Establish empty arrays
			$input_raw = $input_processed = [];

			// Get query string
			parse_str($_SERVER['QUERY_STRING'], $query_string_data);
			# We may eventually include the query string data in all requests. For now, it's just used for authentication and in GET requests.

			// Authenticate request
			if ($route_found['authenticate']) {

				// Ensure auth procedure has been configured
				$auth_procedure = Config::get('rona.api.authentication.procedure');
				if (empty($auth_procedure))
					throw New Exception('The authentication procedure needs to be configured.');

				// Establish input
				$auth_input = [];

				// Add the query string to the input array if it's enabled
				if (Config::get('rona.api.authentication.inject_query_string'))
					$auth_input = $query_string_data;

				// Add header data *** if this is an internal app request, we'll need to use Helper::array_get($_SESSION, $item); That needs to be wired in, though.
				foreach ((array) Config::get('rona.api.authentication.header_params') as $param)
					$auth_input = array_merge($auth_input, [$param => Helper::array_get($_SERVER, strtoupper('http_' . $param))]);

				// Authenticate the user by running the procedure
				$res = Procedure::run($auth_procedure, $auth_input);
				if (!$res->success) {
					http_response_code(401);
					return Helper::call_func($route_found['hooks']['onAuthorization_failure'], $res);
				}

				$auth_user_id = $res->data;
			}

			# Start - get payload message-body

			// If this is a "get" request, get the query string data
			if (Request::http_method() == 'get')
				$input_raw = $query_string_data;

			// Since this isn't a "get" request, we'll get the input that was sent
			else {
				$content_type = Helper::array_get($_SERVER, 'CONTENT_TYPE');

				if ($content_type == 'application/x-www-form-urlencoded')
					parse_str(file_get_contents('php://input'), $input_raw);

				# We're intentionally using the raw $_SERVER['REQUEST_METHOD'] here. This is a work-around that will allow our manual put/patch/etc _http_method override methods to upload files
				else if ($_SERVER['REQUEST_METHOD'] == 'POST' && strstr($content_type, 'multipart/form-data') !== false) {
					$input_raw = $_POST;
					$input_raw = array_merge($input_raw, $_FILES);
				}

				else if ($content_type == 'application/json')
					$input_raw = json_decode(file_get_contents('php://input'), true);

				else
					parse_str(file_get_contents('php://input'), $input_raw);
			}

			// Get the route variables
			$input_raw = array_merge($input_raw, Request::route_vars());

			# End - get payload message-body

			// If the 'set_auth_user_id_as' was set for user authentication, add it
			if ($route_found['set_auth_user_id_as'])
				$input_processed[$route_found['set_auth_user_id_as']] = $auth_user_id;

			// Set params
			foreach (Helper::array_get($route_found, 'set_param', []) as $param => $val)
				$input_processed[$param] = $val;

			$input_raw = array_merge($input_raw, $input_processed);

			// Process the input
			$res = Procedure::process_input($route_found['procedure'], $input_raw);
			if (!$res->success) {
				http_response_code(400);
				return Helper::call_func($route_found['hooks']['onParam_failure'], $res);
			}

			$input_processed = array_merge($res->data, $input_processed);

			// Run authorization checks, if applicable
			if ($route_found['authenticate']) {

				$temp_input = $input_processed;
				$temp_input['auth_user_id'] = $auth_user_id;

				foreach (Helper::array_get($route_found, 'authorizations', []) as $procedure => $switches) {

					$res = Procedure::run($procedure, $temp_input);
					if (!$res->success) {
						http_response_code(403);
						return Helper::call_func($route_found['hooks']['onAuthorization_failure'], $res);
					}
				}
			}
		
			// Run the procedure
			$res = Procedure::execute($route_found['procedure'], $input_processed);
			return Helper::call_func($route_found['hooks']['onSuccess'], $res);
		}

		# The API has finished. If applicable, we'll now load the app.
			
		// Set the current route_tags
		if (!empty($route_found['tags']))
			Request::set('route_tags', $route_found['tags']);
			
		// Set the current route_options
		if (!empty($route_found['options']))
			Request::set('route_options', $route_found['options']);

		// Start session
		if (session_status() == PHP_SESSION_NONE) {
			$save_path = Config::get('rona.system_dir') . Config::get('rona.app.tmp_storage');
			if (!file_exists($save_path))
				mkdir($save_path, 0777, true);
			session_save_path($save_path);
			session_start();
		}

		// Create the scope object
			require_once Config::get('rona.core_dir') . '/Scope.php';
			$scope = Scope::instance();
			
		// Run the controllers
			require_once Config::get('rona.core_dir') . '/Controller.php';
			if (!empty($route_found['controllers']) && is_array($route_found['controllers'])) {
				foreach ($route_found['controllers'] as $controller) {
					
					if (is_callable($controller))
						$controller = $controller($scope);
					
					if (!empty($controller))
						Controller::run($controller, $scope);
				}
			}
			
		// Run the views
			if (!empty($route_found['views']) && is_array($route_found['views'])) {
				
				$output = '';
				foreach ($route_found['views'] as $view) {
					
					if (is_callable($view))
						$view = $view($scope);
					
					if (!empty($view)) {
						ob_start();

							// If the view is wrapped in quotes, simply output the string
								$first_char = substr($view, 0, 1);
								$last_char = substr($view, -1, 1);
								if (($first_char == '"' && $last_char == '"') || ($first_char == "'" && $last_char == "'")) {
									$view = substr($view, 1);
									$view = substr($view, 0, -1);
									echo $view;
								}

							// The view was not a string output, so include the file
								else
									self::load_view($view, $scope);
							$contents = ob_get_contents();
						ob_end_clean();
					}

					if (empty($output))
						$output = $contents;
					else {
					
						// Escape $n backreferences
							$contents = preg_replace('/\$(\d)/', '\\\$$1', $contents);
							
						$output = preg_replace('/{rona_replace}/', $contents, $output, 1);
					}
				}
				
				// Remove any remaining rona_replace place holders and output the views
				echo str_replace('{rona_replace}', '', $output);
			}
	}

	public static function tLoad($type, $name) {

		// Convert $name into an array
		$parts = explode('.', $name);

		// The actual name of the item is the last array item
		$name = end($parts);

		// Remove the last array item, which is the name
		unset($parts[count($parts) - 1]);

		// Determine the location of the file
		if ($parts[0] == 'rona')
			$location = Config::get('rona.core_dir') . '/filters.php';
		else
			$location = Config::get('rona.system_dir') . Config::get(['rona', in_array($type, ['filter', 'procedure']) ? 'api' : 'app', 'locations', $type . 's']) . '/' . implode('/', $parts) . '.php';

		// Load the file
		Helper::load_file($location);

		// Return the name of the item
		return $name;
	}

	public static function load_view($view, $scope) {
		include Config::get('rona.system_dir') . Config::get('rona.app.locations.views') . '/' . $view . '.php';
	}
}