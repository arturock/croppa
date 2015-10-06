<?php namespace Bkwld\Croppa;

// Deps
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handle a Croppa-style request, forwarding the actual work onto other classes.
 */
class Handler extends Controller {

	/**
	 * @var Bkwld\Croppa\Storage
	 */
	private $storage;

	/**
	 * @var Bkwld\Croppa\URL
	 */
	private $url;

	/**
	 * Dependency injection
	 *
	 * @param Bkwld\Croppa\URL $url
	 * @param Bkwld\Croppa\Storage $storage
	 * @param Illuminate\Http\Request $request
	 */
	public function __construct(URL $url, Storage $storage, Request $request) {
		$this->url = $url;
		$this->storage = $storage;
		$this->request = $request;
	}

	/**
	 * Handles a Croppa style route
	 *
	 * @param string $request The `Request::path()`
	 * @throws Exception
	 * @return Symfony\Component\HttpFoundation\StreamedResponse
	 */
	public function handle($request) {
		// TODO: THIS FOR MAKE LUMEN WORK WITH CUSTOM SRC DIRS
		$request = $this->request->path();
		$dir = dirname($request);
		$this->storage->mount($dir, $dir.'/thumbs');

		// Validate the signing token
		if (($token = $this->url->signingToken($request))
			&& $token != $this->request->input('token')) {
			throw new NotFoundHttpException('Token missmatch');
		}

		// Get crop path relative to it's dir
		$crop_path = $this->url->relativePath($request);

		$remote_crops = $this->storage->cropsAreRemote();

		if (!$this->storage->cropExists($crop_path)) {
			// Parse the path.  In the case there is an error (the pattern on the route
			// SHOULD have caught all errors with the pattern) just return
			if (!$params = $this->url->parse($request)) return;

			list($path, $width, $height, $options) = $params;

			// Check if there are too many crops already
			if ($this->storage->tooManyCrops($path)) throw new Exception('Croppa: Max crops');

			// Increase memory limit, cause some images require a lot to resize
			ini_set('memory_limit', '128M');

			// Build a new image using fetched image data
			$image = new Image(
				$this->storage->readSrc($path),
				$this->url->phpThumbConfig($options)
			);

			// Process the image and write its data to disk
			$this->storage->writeCrop($crop_path,
				$image->process($width, $height, $options)->get()
			);
		}

		// Redirect to remote crops ...
		if ($remote_crops) {
			return new RedirectResponse($this->url->pathToUrl($crop_path), 301);

		// ... or echo the image data to the browser
		} else {
			$absolute_path = $this->storage->getLocalCropsDirPath().'/'.$crop_path;
			return new BinaryFileResponse($absolute_path, 200, [
				'Content-Type' => $this->getContentType($crop_path),
			]);
		}

	}

	/**
	 * Symfony kept returning the MIME-type of my testing jpgs as PNGs, so
	 * determining it explicitly via looking at the path name.
	 *
	 * @param string $path
	 * @return string
	 */
	public function getContentType($path) {
		switch(pathinfo($path, PATHINFO_EXTENSION)) {
			case 'jpeg':
			case 'jpg': return 'image/jpeg';
			case 'gif': return 'image/gif';
			case 'png': return 'image/png';
		}
	}

}
