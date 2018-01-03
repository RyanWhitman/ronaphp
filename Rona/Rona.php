<?php
/**
 * @package RonaPHP
 * @copyright Copyright (c) 2017 Ryan Whitman (https://ryanwhitman.com)
 * @license https://opensource.org/licenses/MIT   MIT
 * @version 1.0.0 - beta
 * @link https://github.com/RyanWhitman/ronaphp/tree/v1
 * @since 1.0.0 - beta
 */

namespace Rona;

class Rona {

	protected $config;

	protected $modules = [];

	public function __construct() {

		// Register the Rona classes using spl_autoload_register.
		$this->spl_autoload_register();

		// Create a config object.
		$this->config = new Config\Config;

		// Register the stock configuration.
		$this->register_stock_config();

		// Register the configuration.
		$this->register_config();

		// Register the modules.
		$this->register_modules();
	}

	protected function spl_autoload_register(): bool {
		return spl_autoload_register(function($class) {
			$file = dirname(__DIR__) . '/' . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
			if (file_exists($file)) {
				require_once $file;
				return true;
			}
			return false;
		});
	}

	protected function register_stock_config() {
		$this->config()->set('base_path', '');
		$this->config()->set('request_path', strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
		$this->config()->set('http_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']);
		$this->config()->set('template_placeholder_replace_text', '%PH%');
		$this->config()->set('template_placeholder', '{% ' . $this->config('template_placeholder_replace_text') . ' %}');
		$this->config()->set('view_assets')
			->_('template', function(\Rona\Module $module, string $template) {
				return $template;
			})
			->_('stylesheet', function(\Rona\Module $module, string $item) {
				return "<link href=\"$item\" rel=\"stylesheet\">
				";
			})
			->_('javascript', function(\Rona\Module $module, string $item) {
				return "<script src=\"$item\"></script>
				";
			})
			->_('file', function(\Rona\Module $module, string $item) {
				return $item;
			});
	}

	protected function register_config() {}

	public function config(string $item = NULL) {
		return is_null($item) ? $this->config : $this->config->get($item);
	}

	protected function register_modules() {}

	public function register_module(string $id, string $class_name) {

		// Ensure the module ID is unique.
		if ($this->get_module($id))
			throw new \Exception("Module IDs must be unique, but '$id' is already in use.");

		// Ensure the qualified class name is a subclass of the \Rona\Module class.
		if (!is_subclass_of($class_name, '\Rona\Module'))
			throw new \Exception("Modules must be a subclass of '\Rona\Module', but '$class_name' is not.");

		// Instantiate the module.
		$module = new $class_name($id, $this);

		// Store the module.
		$this->modules[$id] = $module;

		// Execute the module's module_registered method.
		$module->module_registered();
	}

	public function get_modules(): array {
		return $this->modules;
	}

	public function get_module(string $id) {
		return $this->get_modules()[$id] ?? false;
	}

	/**
	 * Run a hook on all modules.
	 * 
	 * @param    string    $name    The name of the hook.
	 * @param    mixed     $args    Optional args to pass to the hook callback.
	 * @return   array              An associative array in which the key represents the module ID and the value represents the return value of the hook.
	 */
	public function run_hook(string $name, ...$args): array {

		// Create an empty array to hold the module responses.
		$res = [];

		// Add the hook name to the args array.
		array_unshift($args, $name);

		// Loop thru each module and run the hook. Store the hook response in an array.
		foreach ($this->get_modules() as $module)
			$res[$module->get_id()] = call_user_func_array([$module, 'run_hook'], $args);

		// Response
		return $res;
	}

	public function find_route(HTTP_Request $http_request, Routing\Route $route, HTTP_Response\Response $http_response) {

		// Create a route matching object.
		$route_matcher = new Routing\Matcher;
	
		// Loop thru each module and get the matching routes.
		$route_queues = [];
		$non_abstract = false;
		$non_abstract_module = NULL;
		$no_route = false;
		$no_route_module = NULL;
		foreach ($this->get_modules() as $module) {
			foreach (['abstract', 'non_abstract', 'no_route'] as $type) {

				// Get the matching routes.
				$matches = $route_matcher->get_matches($module->route_store[$type]->get_routes(), $http_request->get_method(), $http_request->get_path());
				if (!empty($matches)) {
					if ($type == 'abstract') {
						foreach ($matches as $match)
							$route_queues[] = ['module' => $module, 'route_queue' => $match['route_queue']];
					} else {
						${$type} = array_pop($matches);
						${$type . '_module'} = $module;
					}
				}
			}
		}

		// Determine which route to use.
		if ($non_abstract || $no_route) {
			$route->route_found = true;
			if ($non_abstract) {
				$route_to_use = $non_abstract;
				$route_module_to_use = $non_abstract_module;
			} else {
				$route->is_no_route = true;
				$http_response->set_code(404);
				$route_queues = [];
				$route_to_use = $no_route;
				$route_module_to_use = $no_route_module;
			}
		} else
			return false;			

		$http_request->set_path_vars($route_to_use['path_vars']);
		
		// Add the non-abstract route to the end of the route queues array.
		$route_queues[] = ['module' => $route_module_to_use, 'route_queue' => $route_to_use['route_queue']];

		// Set the route module in the HTTP response object.
		$http_response->set_route_module($route_module_to_use);

		// Loop thru each route queue and execute.
		foreach ($route_queues as $route_queue) {
			$route->set_active_module($route_queue['module']);
			$route_queue['route_queue']->process($route);
		}
	}

	public function execute_route(HTTP_Request $http_request, Routing\Route $route, Scope $scope, HTTP_Response\Response $http_response) {

		// Run the controllers.
		while (1) {
			$controllers = $route->get_controllers();
			if (empty($controllers))
				break;
			$the_controller = $controllers[0];
			unset($controllers[0]);
			$route->remove_controllers();
			foreach ($controllers as $controller)
				$route->append_controller($controller);

			$route->set_active_module($the_controller['module']);
			$http_response->set_active_module($the_controller['module']);

			$res = call_user_func($the_controller['callback'], $http_request, $route, $scope, $http_response);
			if ($res === false)
				break;
			else if (is_a($res, '\Rona\HTTP_Response\Response'))
				$http_response = $res;
		}

		// Authentication
		$passed_authentication = true;
		$authentication = $route->get_authentication();
		if ($authentication && $authentication() === false) {
			$passed_authentication = false;
			if (is_null($http_response->get_code()))
				$http_response->set_code(401);
		}
		if ($passed_authentication) {

			// Process input
			$passed_input_validation = true;
			$procedure = $route->get_procedure();
			if ($procedure) {
				$process_input_res = $procedure['module']->run_procedure($procedure['full_procedure_name'], $http_request->get_input(), 'process_input');
				if (!$process_input_res->success) {
					$passed_input_validation = false;
					$failed_input_handler = Helper::maybe_closure($procedure['failed_input_handler'], $process_input_res->data);
					$msgs = [];
					if (is_string($failed_input_handler))
						$msgs[] = $failed_input_handler;
					else if (is_array($failed_input_handler)) {
						foreach ($process_input_res->data as $param => $data) {
							if (isset($failed_input_handler[$param])) {
								$failed_input_handler[$param] = Helper::maybe_closure($failed_input_handler[$param], $data);
								if (is_string($failed_input_handler[$param]))
									$msgs[] = $failed_input_handler[$param];
								else if (is_array($failed_input_handler[$param]) && isset($failed_input_handler[$param][$data['tag']])) {
									$failed_input_handler[$param][$data['tag']] = Helper::maybe_closure($failed_input_handler[$param][$data['tag']], $data);
									if (is_string($failed_input_handler[$param][$data['tag']]))
										$msgs[] = $failed_input_handler[$param][$data['tag']];
								}
							}
						}
					}
					if (!empty($msgs))
						$http_response->api()->set_messages($msgs);
					if (is_null($http_response->get_code()))
						$http_response->set_code(400);
				} else
					$http_request->set_processed_input($process_input_res->data);
			}
			if ($passed_input_validation) {

				// Authorization
				$passed_authorization = true;
				$authorization = $route->get_authorization();
				if ($authorization && $authorization() === false) {
					$passed_authorization = false;
					if (is_null($http_response->get_code()))
						$http_response->set_code(403);
				}
				if ($passed_authorization) {

					// Procedure execution
					if ($procedure) {
						$procedure_res = $procedure['module']->run_procedure($procedure['full_procedure_name'], $http_request->get_processed_input(), 'execute');
						$procedure_handler = Helper::maybe_closure($procedure['procedure_handler'], $procedure_res);
						$msg = '';
						if (is_string($procedure_handler))
							$msg = $procedure_handler;
						else if (is_array($procedure_handler) && isset($procedure_handler[$procedure_res->tag])) {
							$procedure_handler[$procedure_res->tag] = Helper::maybe_closure($procedure_handler[$procedure_res->tag], $procedure_res);
							if (is_string($procedure_handler[$procedure_res->tag]))
								$msg = $procedure_handler[$procedure_res->tag];
						}
						if ($msg)
							$http_response->api()->set_messages($msg);
						if (is_null($http_response->get_code()))
							$http_response->set_code($procedure_res->success ? ($http_request->get_method() == 'POST' ? 201 : 200) : 400);
					}
				}
			}
		}

		// Finalize
		$finalize = $route->get_finalize();
		if ($finalize)
			$finalize();
	}

	public function output_route(HTTP_Response\Response $http_response) {
		$http_response->output();
	}

	public function run() {

		// Create the HTTP Request, Route, Scope, and HTTP Response objects.
		$http_request = new HTTP_Request($this);
		$route = new Routing\Route;
		$scope = new Scope($http_request);
		$http_response = new HTTP_Response\Response($this, $scope);

		$this->find_route($http_request, $route, $http_response);
		$this->execute_route($http_request, $route, $scope, $http_response);
		$this->output_route($http_response);
	}
}