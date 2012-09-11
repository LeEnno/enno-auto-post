<?php
require_once 'IXR_Library.php';

class EnnoAutoPost
{
	/**
	 * config
	 */
	private $_user;
    private $_pass;
    private $_url;
	private $_imgMaxWidth;
	private $_imgMaxHeight;

	/**
	 * constants
	 */
	const DELIMITER = '|';

	/**
	 * internals
	 */
	private $_client;
	private $_title;
	private $_content;
	private $_slug;
	private $_tags;
	private $_categories;
	private $_excerpt;
	private $_postData = array();


	/**
	 * Creates a client instance for XML-RPC requests and sets the post's
	 * initial content.
	 *
	 * @param string $htmlString	Sets the post's initial content.
	 * @param string $identifier	Fetches the correct set of config data.
	 */
	public function __construct($htmlString, $identifier)
	{
	    $config = parse_ini_file('config.ini', true);
	    if (!isset($config[$identifier])) {
	        var_dump($config);
	        exit("could not find identifier '$identifier'");
	    }
	    $config = $config[$identifier];
	    
	    $this->_user = $config['user'];
	    $this->_pass = $config['pass'];
	    $this->_url = $config['url'];
	    $this->_imgMaxWidth = $config['max_width'];
		$this->_imgMaxHeight = $config['max_height'];

		$this->_client = new IXR_Client($this->_url."/xmlrpc.php");
		$this->_content = $htmlString;
	}

	/**
	 * Creates a new post.
	 *
	 * The post itself remains empty, since we may have to replace html markup.
	 *
	 * @return int The inserted post's ID.
	 */
	public function createPost()
	{
		$user = $this->_user;
		$pass = $this->_pass;
		$this->_postData['post_content'] = $this->_content;
		$this->_postData['comment_status'] = 'open';

		if (!$this->_client->query('wp.newPost', 1, $user, $pass, $this->_postData))
			$this->_displayError('creating post');

		$id = $this->_client->getResponse();
		return $this->_url."/wp-admin/post.php?post=".$id."&action=edit";
	}

	/**
	 * Looks for headline and meta-Tags and sets them.
	 *
	 * The $data-Array includes the properties to set as keys and the patterns
	 * to apply to a regular expression as values.
	 */
	public function setMetadata()
	{
		$wildcard = "([^<\n]+)";
		$data = array(
			'_title' => '<h1>(.*)</h1>',
			'_slug' => "@slug: $wildcard",
			'_excerpt' => "@excerpt: $wildcard",
			'_tags' => "@tags: $wildcard",
			'_categories' => "@categories: $wildcard"
		);
		$this->_extractPostMetadata($data);

		// title
		if (is_null($this->_title) && $this->_title != 'TODO')
			exit("title missing");
		else
			$this->_postData['post_title'] = $this->_title;

		// slug
		if (is_null($this->_slug) && $this->_slug != 'TODO')
			exit("slug missing");
		else
			$this->_postData['post_name'] = $this->_slug;

		// excerpt
		if (is_null($this->_excerpt) && $this->_excerpt != 'TODO')
			exit("excerpt missing");
		else
			$this->_postData['post_excerpt'] = $this->_excerpt;

		// tags
		if (is_null($this->_tags) && $this->_tags != 'TODO')
			exit("tags missing");
		else {
			$taxonomyName = 'post_tag';
			$cats = explode(', ', $this->_tags);
			$this->_addTaxonomyItems($taxonomyName, $cats);
		}

		// categories
		if (is_null($this->_categories) && $this->_categories != 'TODO')
			exit("categories missing");
		else {
			$taxonomyName = 'category';
			$cats = explode(', ', $this->_categories);
			$this->_addTaxonomyItems($taxonomyName, $cats);
		}
	}

	/**
	 * Finds images and adjusts the markup so the code fits to what adding
	 * images via the WordPress frontend would result in.
	 */
	public function replaceImageMarkup()
	{
		$pattern = '<img src="([^"]+)" alt="([^"]+)" />';
		$pattern = self::DELIMITER . $pattern . self::DELIMITER;
		$this->_content = preg_replace_callback(
			$pattern,
			"EnnoAutoPost::_replaceImages",
			$this->_content
		);
	}

	/**
	 * Replaces the generated <pre><code>-combination with a single
	 * <pre>-wrapper.
	 */
	public function replaceCode()
	{
		$this->_content = str_replace('<pre><code>', '<pre>', $this->_content);
		$this->_content = str_replace('</code></pre>', '</pre>', $this->_content);
	}

	/**
	 * @param array $matches	Contains complete match (index 0) first submatch
	 * 							(image source, index 1) and second submatch
	 * 							(image title, index 2)
	 * @return string $output	The updated output.
	 */
	private function _replaceImages($matches)
	{
		$path = $matches[1];
		$title = $matches[2];

		// read picture data
		if (!file_exists($path))
			$this->_displayError('checking file', "File $path does not exist!");
		if (!$filestream = file_get_contents($path))
			$this->_displayError('checking file', "Could not get contents");

		// set variables
		$fileMeta = $this->_extractFilenameData($path);
		$filename = $fileMeta['basename'] . $fileMeta['extension'];
		$sizeData = getimagesize($path);
		$type = $sizeData['mime'];
		$width = $sizeData[0];
		$height = $sizeData[1];

		// adjust dimensions if necessary
		$sizeAppend = "";
		if ($width > $this->_imgMaxWidth && $width >= $height) {
			$ratio = $this->_imgMaxWidth / $width;
			$width = $this->_imgMaxWidth;
			$height = floor($height * $ratio);
			$sizeAppend = "-{$width}x{$height}";
		} elseif ($height > $this->_imgMaxHeight && $width < $height) {
			$ratio = $this->_imgMaxHeight / $height;
			$height = $this->_imgMaxHeight;
			$width = floor($width * $ratio);
			$sizeAppend = "-{$width}x{$height}";
		}

		// upload picture
		$user = $this->_user;
		$pass = $this->_pass;
		$data = array(
			'name' => $filename,
			'type' => $type,
			'bits' => new IXR_Base64($filestream)
		);

		if (!$this->_client->query('wp.uploadFile', 1, $user, $pass, $data))
			$this->_displayError("uploading photo $path");

		$response = $this->_client->getResponse();

		$imageID = $response['id'];
		$imageUrl = $response['url'];
		$fileMeta = $this->_extractFilenameData($imageUrl); // necessary for building image-URL

		// add title and other data
		$data = array(
			'post_title' => $title,
			'post_excerpt' => $title,
			'post_content' => $title
		);

		if (!$this->_client->query('wp.editPost', 1, $user, $pass, $imageID, $data))
			$this->_displayError("editing photo $path");

		// prepare output
		$titleEscaped = htmlspecialchars($title);
		$srcFinal = $fileMeta['basepath'] . $fileMeta['basename'] . $sizeAppend . $fileMeta['extension'];
		$output = "[caption id='attachment_$imageID' align='aligncenter' width='$width' caption='$titleEscaped']";
		$output.= "<a href='$imageUrl'>";
		$output.= "<img class='wp-image-$imageID' title='$titleEscaped' src='$srcFinal' alt='$titleEscaped' width='$width' height='$height' />";
		$output.= "</a> {$title}[/caption]";

		return $output;
	}

	/**
	 * Returns an image's file' basepath, basename and extension.
	 *
	 * @param string $path	The image's path.
	 * @return array		Contains basepath, basename and extension.
	 */
	private function _extractFilenameData($path)
	{
		if ($indexOfLastSlash = strrpos($path, '/')) {
			$filename = substr($path, $indexOfLastSlash + 1);
			$basepath = substr($path, 0, $indexOfLastSlash + 1);
		} else {
			$filename = $path;
			$basepath = '';
		}

		$indexOfLastDot = strrpos($filename, '.');
		$basename = substr($filename, 0, $indexOfLastDot);
		$extension = substr($filename, $indexOfLastDot);

		return array(
			'basepath' => $basepath,
			'basename' => $basename,
			'extension' => $extension
		);
	}

	/**
	 * Saves additional info based upon a regular expression and deletes the
	 * affected text lines afterwards.
	 *
	 * @param array $data	Contains pairs of properties to set (keys) and
	 * 						patterns to apply (values).
	 */
	private function _extractPostMetadata($data)
	{
		foreach ($data as $prop => $pattern) {
			$pattern = self::DELIMITER . $pattern . self::DELIMITER;
			preg_match($pattern, $this->_content, $matches);
			if (isset($matches[1])) {
				$this->$prop = $matches[1];
				$this->_content = trim(preg_replace($pattern, '', $this->_content));
			}
		}

		// clean whitespace and empty paragraphs
		$this->_content = preg_replace(
			self::DELIMITER . "<p>\n+</p>\n*" . self::DELIMITER,
			'',
			$this->_content
		);
	}

	/**
	 * Adds taxonomy items as metadata.
	 *
	 * @param string $key
	 * @param array $data
	 */
	private function _addTaxonomyItems($key, $data)
	{
		if (isset($this->_postData['terms_names']))
			$this->_postData['terms_names'][$key] = $data;
		else
			$this->_postData['terms_names'] = array($key => $data);
	}

	/**
	 * Displays error message and quits execution.
	 *
	 * @param string $position	Position where error occured.
	 * @param string $msg		The message to display.
	 */
	private function _displayError($position, $msg = '')
	{
		if (empty($msg)) {
			$code = $this->_client->getErrorCode();
			$msg = $this->_client->getErrorMessage();
		} else
			$code = '666';

		echo "Position: $position<br />";
		exit("An error occurred - $code: $msg");
	}
}