<?php

use \Chumper\Zipper\Zipper;

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

		$info_file = View::make("d{$version}_info", [
			'module_name' => $input['module_name'],
			'dependencies' => $dependencies,
			'description' => $description,
		])->render();

		$tempfoldername = storage_path().'/downloads/modulename_'.$name;

		if (!File::isDirectory($tempfoldername)) {
			File::makeDirectory($tempfoldername);
		} else {
			File::cleanDirectory($tempfoldername);
		}

		File::put($tempfoldername."/{$name}.info", $info_file);

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
			return Response::download($zipfilename, "$name");
		}

		dd(Input::all());
	}

});
