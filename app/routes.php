<?php

use \Chumper\Zipper\Zipper;
use \Symfony\Component\Yaml\Dumper;
use \Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/

/**
 * Turn this on to enable printing to the screen & not zipping & creating files.
 * @var boolean
 */
$debug = false;

Route::get('/', function() use ($debug)
{

	// Set an hourly cache to clean the folders out.
	if (!Cache::has('cleanup')) {
			File::cleanDirectory(storage_path().'/downloads');
			Cache::put('cleanup', 1, 3600);
	}

	return View::make('hello', [
		'input' => Input::old()
	]);
});

Route::post('process', function() use ($debug)
{
	$input = Input::all();

	$rules = [
		'module_name' => 'required|min:3',
		'module_description' => 'required|min:3',
	];

	$validator = Validator::make($input, $rules);

	if ($validator->fails()) {
		return Redirect::to('/')->withErrors($validator)->withInput($input);
	} else {

		$name = Str::slug($input['module_name'], '_');

		$description = $input['module_description'];

		$version = (int)$input['drupal_version'];

		$dependencies = explode(',', $input['dependencies']);

		if (empty($dependencies[0])) {
			$dependencies = [];
		}

		$module_info = [
			'name' => $input['module_name'],
			'dependencies' => $dependencies,
			'description' => $description,
		];

		if (in_array($version, array(6,7))) {
			$info_file = View::make("d{$version}_info", $module_info)->render();
		}
		else if ($version == 8) {
			$module_info['core'] = '8.x';
			$module_info['package'] = 'Custom';
			$module_info['type'] = 'module';
			$dumper = new Dumper();
			$info_file = $dumper->dump($module_info, 2);
		}

		$tempfoldername = storage_path().'/downloads/modulename_'.$name;

		if (!File::isDirectory($tempfoldername)) {
			File::makeDirectory($tempfoldername);
		} else {
			File::cleanDirectory($tempfoldername);
		}

		// Drupal 8 ? Ensure that src/Controllers exists
		if ($version == 8) {
			$tempSrcDir = storage_path().'/downloads/modulename_'.$name.'/src/Controller';
			if (!File::isDirectory($tempSrcDir)) {
				File::makeDirectory($tempSrcDir, 0777, true);
			} else {
				File::cleanDirectory($tempSrcDir);
			}
		}

		// Is this Drupal 8, does it need a .yaml extension?
		$suffix = $version == 8 ? '.yml' : '';
		File::put($tempfoldername."/{$name}.info".$suffix, $info_file);

		// Create a routing file.
		if ($version == 8) {
			$namespace = '\\Drupal\\'.$name.'\\Controller';
			$className = str_replace(' ', '', ucwords(strtolower($module_info['name'])));
			$controllerClassname = $className.'StartController';
			$fullClassPath = $namespace.'\\'.$controllerClassname;
			$routing = [
				$name.'.start' => [
					'path' => '/custom/start',
					'defaults' => [
						'_controller' => $fullClassPath.'::startAction',
					],
					'requirements' => [
						'_permission' => 'access content',
					],
				],
			];

			// Output the YAML routing file.
			if (File::isDirectory($tempSrcDir)) {
				File::put($tempfoldername."/{$name}.routing.yml", Yaml::dump($routing, 20));
				File::put($tempfoldername."/src/Controller/{$controllerClassname}.php", View::make('d8controller', ['namespace' => $name, 'controller_name' => $controllerClassname])->render());
			}
		}
		/** Module File **/
		$module_file = View::make("module", [
			'module_name' => $name,
			'dependencies' => $dependencies,
			'description' => $description,
		])->render();
		if (File::isDirectory($tempfoldername)) {
			File::put($tempfoldername."/{$name}.module", $module_file);
		}

		if ($debug === true) {
			// Just output to the screen, don't do anything else.
			return Response::make($info_file . "\n\n\n\n" . $module_file)->header('Content-type', 'text/plain');
		}

		/** Zip the thing up **/

		$zipfilename = $tempfoldername."/zip_$name.zip";

		if (File::exists($zipfilename)) {
			File::delete($zipfilename);
		}

		$zipper = new Zipper();

		$zipper->make($zipfilename)->add($tempfoldername);
		if ($zipper->getStatus() == 'No error') {
			$zipper->close();
			return Response::download($zipfilename.'.zip', "$name");
		}
	}

});
