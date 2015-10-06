<?php namespace Bkwld\Croppa;

/**
 * Appends and parses params of URLs
 */
class URL {

	/**
	 * The pattern used to indetify a request path as a Croppa-style URL
	 * https://github.com/BKWLD/croppa/wiki/Croppa-regex-pattern
	 *
	 * @return string
	 */
	const PATTERN = '(.+)-([0-9_]+)x([0-9_]+)(-[0-9a-zA-Z(),\-._]+)*\.(jpg|jpeg|png|gif|JPG|JPEG|PNG|GIF)$';

	/**
	 * Croppa general configuration
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Inject dependencies
	 *
	 * @param array $config
	 */
	public function __construct($config = []) {
		$this->config = $config;
	}

	/**
	 * Insert Croppa parameter suffixes into a URL.  For use as a helper in views
	 * when rendering image src attributes.
	 *
	 * @param string $url URL of an image that should be cropped
	 * @param integer $width Target width
	 * @param integer $height Target height
	 * @param array $options Addtional Croppa options, passed as key/value pairs.  Like array('resize')
	 * @return string The new path to your thumbnail
	 */
	public function generate($url, $width = null, $height = null, $options = null) {

		// Extract the path from a URL and remove it's leading slash
		$path = $this->toPath($url);

		// Skip croppa requests for images the ignore regexp
		if (null !== $this->setting('ignore')
			&& preg_match('#'.$this->setting('ignore').'#', $path)) {
			return $this->pathToUrl($path);
		}

		// Defaults
		if (empty($path)) return; // Don't allow empty strings
		if (!$width && !$height) return $this->pathToUrl($path); // Pass through if empty
		$width = $width ? round($width) : '_';
		$height = $height ? round($height) : '_';

		// Produce width, height, and options
		$suffix = '-'.$width.'x'.$height;
		if ($options && is_array($options)) {
			foreach($options as $key => $val) {
				if (is_numeric($key)) $suffix .= '-'.$val;
				elseif (is_array($val)) $suffix .= '-'.$key.'('.implode(',',$val).')';
				else $suffix .= '-'.$key.'('.$val.')';
			}
		}

		// Assemble the new path
		$parts = pathinfo($path);
		$path = trim($parts['dirname'],'/').'/'.$parts['filename'].$suffix;
		if (isset($parts['extension'])) $path .= '.'.$parts['extension'];
		$url = $this->pathToUrl($path);

		// Secure with hash token
		if ($token = $this->signingToken($url)) $url .= '?token='.$token;

		// Return the $url
		return $url;
	}

	/**
	 * Extract the path from a URL and remove it's leading slash
	 *
	 * @param string $url
	 * @return string path
	 */
	public function toPath($url) {
		return ltrim(parse_url($url, PHP_URL_PATH), '/');
	}

	/**
	 * Append host to the path if it was defined
	 *
	 * @param string $path Request path (with leading slash)
	 * @return string
	 */
	public function pathToUrl($path) {
		if (empty($this->setting('url_prefix'))) return '/'.$path;
		if (empty($this->setting('path'))) return rtrim($this->setting('url_prefix'), '/').'/'.$path;
		return rtrim($this->setting('url_prefix'), '/').'/'.$this->relativePath($path);
	}

	/**
	 * Generate the signing token from a URL or path.  Or, if no key was defined,
	 * return nothing.
	 *
	 * @param string path or url
	 * @return string|void
	 */
	public function signingToken($url) {
		if (null !== $this->setting('signing_key')
			&& ($key = $this->setting('signing_key'))) {
			return md5($key.basename($url));
		}
	}

	/**
	 * Make the regex for the route definition. This works by wrapping both the
	 * basic Croppa pattern and the `path` config in positive regex lookaheads so
	 * they working like an AND condition.
	 * https://regex101.com/r/kO6kL1/1
	 *
	 * In the Laravel router, this gets wrapped with some extra regex before the
	 * matching happnens and for the pattern to match correctly, the final .* needs
	 * to exist.  Otherwise, the lookaheads have no length and the regex fails
	 * https://regex101.com/r/xS3nQ2/1
	 *
	 * @return array
	 */
	public function routePatterns() {
		if (!is_array($paths = $this->setting('path'))) {
			return [sprintf("(?=%s)(?=%s).+", $this->setting('path'), self::PATTERN)];
		}

		foreach ($paths as $path) {
			$rxPaths[] = sprintf("(?=%s)(?=%s).+", $path, self::PATTERN);
		}

		return $rxPaths;
	}

	/**
	 * Parse a request path into Croppa instructions
	 *
	 * @param string $request
	 * @return array | boolean
	 */
	public function parse($request) {
		if (!preg_match('#'.self::PATTERN.'#', $request, $matches)) return false;
		return [
			$this->relativePath($matches[1].'.'.$matches[5]), // Path
			$matches[2] == '_' ? null : (int) $matches[2],    // Width
			$matches[3] == '_' ? null : (int) $matches[3],    // Height
			$this->options($matches[4]),                      // Options
		];
	}

	/**
	 * Take a URL or path to an image and get the path relative to the src and
	 * crops dirs by using the `path` config regex
	 *
	 * @param string $url url or path
	 * @return string
	 */
	public function relativePath($url) {
		$path = $this->toPath($url);
		$paths = $this->setting('path');
		foreach ($paths as $config_path) {
			if (preg_match('#'.$config_path.'#', $path, $matches)) {
				return $matches[1];
			}
		}

		throw new Exception("$url doesn't match any of the configured paths in '".json_encode($paths)."' setting");
	}

	/**
	 * Create options array where each key is an option name
	 * and the value if an array of the passed arguments
	 *
	 * @param  string $option_params Options string in the Croppa URL style
	 * @return array
	 */
	public function options($option_params) {
		$options = array();

		// These will look like: "-quadrant(T)-resize"
		$option_params = explode('-', $option_params);

		// Loop through the params and make the options key value pairs
		foreach($option_params as $option) {
			if (!preg_match('#(\w+)(?:\(([\w,.]+)\))?#i', $option, $matches)) continue;
			if (isset($matches[2])) $options[$matches[1]] = explode(',', $matches[2]);
			else $options[$matches[1]] = null;
		}

		// Return new options array
		return $options;
	}

	/**
	 * Take options in the URL and options from the config file and produce a
	 * config array in the format that PhpThumb expects
	 *
	 * @param array $options The url options from `parseOptions()`
	 * @return array
	 */
	public function phpThumbConfig($options) {
		return [
			'jpegQuality' => isset($options['quality']) ? $options['quality'][0] : $this->setting('jpeg_quality'),
			'interlace' => isset($options['interlace']) ? $options['interlace'][0] : $this->setting('interlace'),
			'resizeUp' => isset($options['upscale']) ? $options['upscale'][0] : $this->setting('upscale'),
		];
	}

	/**
	 * Gets a setting from the config array.
	 *
	 * @param string $key     The requested setting key
	 * @param string $default Value to return in case no setting is found
	 *
	 * @return mixed
	 */
	protected function setting($key, $default = null) {
		if (!isset($this->config[$key])) return $default;

		return is_object($setting = $this->config[$key]) && $setting instanceof \Closure ? $setting() : $setting;
	}
}
