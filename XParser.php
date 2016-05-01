<?

class XSelector {
	public $id = false;
	public $tag = false;
	public $class_names = array();

	public static function parse($text) {
		$parts = explode(' ', $text);
		$ret = array();
		foreach($parts as $p) {
			if (!$p) continue;
			$selector = new XSelector();
			$chars = preg_split('/[\.#]/', $p, -1, PREG_SPLIT_OFFSET_CAPTURE);
			foreach($chars as $ch) {
				if ($ch[1] == 0) {
					if ($ch[0]) {
						$selector->tag = $ch[0];
					}
				} else {
					if ($p[$ch[1] - 1] == '#') {
						$selector->id = $ch[0];
					} else if ($p[$ch[1] - 1] == '.') {
						$selector->class_names []= $ch[0];
					}
				}
			}
			$ret []= $selector;
		}
		return $ret;
	}
}

class XNode {
	public $tag;
	public $start;
	public $end;
	public $inner_start;
	public $inner_end;
	public $attributes = array();
	public $children = array();
	public $parent;

	public function attr($key) {
		return isset($this->attributes[$key]) ? $this->attributes[$key] : false;
	}

	public function setAttr($key, $value) {
		$this->attributes[$key] = $value;
	}

	public function text($content) {
		if ($this->tag == 'TEXT') {
			return self::toText(substr($content, $this->start, $this->end - $this->start + 1));
		}
		$text = '';
		foreach($this->children as $child) {
			$text .= $child->text($content);
		}
		return $text;
	}

	public function innerHTML($content) {
		return substr($content, $this->inner_start, $this->inner_end - $this->inner_start + 1);
	}

	public function outerHTML($content) {
		return substr($content, $this->start, $this->end - $this->start + 1);
	}

	public static function toText($text) {
		//$text = str_replace(array('&nbsp;'), array(' '), $text); 
		$text = htmlspecialchars_decode($text);
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = preg_replace_callback('/(&#[0-9]+;)/', function($m) {
			return mb_convert_encoding($m[1], 'UTF-8', 'HTML-ENTITIES');
		}, $text); 
		return $text;
	}

	public function is($selector) {
		$sels = XSelector::parse($selector);
		if (!$sels || count($sels) > 1) return false;
		return $this->match($sels[0]);
	}

	public function match($selector) {
		if ($selector->tag !== false && $selector->tag != $this->tag) {
			return false;
		}
		if ($selector->id !== false && (!isset($this->attributes['id']) || $selector->id != $this->attributes['id'])) {
			return false;
		}
		if ($selector->class_names) {
			if (!isset($this->attributes['class'])) {
				return false;
			}
			foreach ($selector->class_names as $class_name) {
				if (!in_array($class_name, $this->attributes['class'])) {
					return false;
				}
			}
		}
		return true;
	}

	public function children($selector) {
		if (!$this->children) return array();
		if (!$selector) return $this->children;
		$parts = explode(',', $selector);
		$selectors = array();
		foreach($parts as $p) {
			if (!$p) continue;
			$sels = XSelector::parse($p);
			if (!$sels || count($sels) > 1) return array();
			$selectors []= $sels[0];
		}
		$ret = array();
		foreach($this->children as $child) {
			foreach($selectors as $sel) {
				if ($child->match($sel)) {
					$ret []= $child;
				}
			}
		}
		return $ret;
	}

	public function find($selector) {
		$parts = explode(',', $selector);
		if (count($parts) <= 1) return $this->findSingleRule($selector);
		$ret = array();
		foreach($parts as $p) {
			if (!$p) continue;
			$matched = $this->findSingleRule($p);
			if (!$matched) continue;
			if (!$ret) {
				$ret = $matched;
			} else {
				$merged = array();
				while($ret || $matched) {
					if (!$ret) {
						$merged []= array_shift($matched);
					} else if (!$matched) {
						$merged []= array_shift($ret);
					} else {
						if ($ret[0]->start < $matched[0]->start) {
							$merged []= array_shift($ret);
						} else if ($ret[0]->start > $matched[0]->start) {
							$merged []= array_shift($matched);
						} else {
							$merged []= array_shift($ret);
							array_shift($matched);
						}
					}
				}
				$ret = $merged;
			}
		}
		return $ret;
	}

	private function findSingleRule($selector) {
		$sels = XSelector::parse($selector);
		if (!$sels) return array();

		$ret = array($this);

		foreach($sels as $i => $sel) {
			if (!$ret) break;
			$islast = ($i == count($sels) - 1);
			$matched = array();
			while ($ret) {
				$node = array_shift($ret);
				foreach($node->children as $child) {
					if ($child->match($sel)) {
						$matched []= $child;
						if (!$islast) continue;
					}
					$ret []= $child;
				}
			}
			$ret = $matched;
		}
		return $ret;
	}

	public function findFirst($selector) {
		$parts = explode(',', $selector);
		if (count($parts) <= 1) return $this->findFirstSingleRule($selector);
		$ret = false;
		foreach($parts as $p) {
			if (!$p) continue;
			$node = $this->findFirstSingleRule($p);
			if (!$node) continue;
			if (!$ret || $ret->start > $node->start) {
				$ret = $node;
			}
		}
		return $ret;
	}

	public function findFirstSingleRule($selector) {
		$sels = XSelector::parse($selector);
		if (!$sels) return false;

		$ret = array($this);

		foreach($sels as $i => $sel) {
			if (!$ret) break;
			$islast = ($i == count($sels) - 1);
			$filtered = array();
			while ($ret) {
				$node = array_shift($ret);
				foreach($node->children as $child) {
					if ($child->match($sel)) {
						if ($islast) return $child;
						$filtered []= $child;
					} else {
						$ret []= $child;
					}
				}
			}
			$ret = $filtered;
		}
		return false;
	}
}

class XParser {
	public static function isSplitter($ch) {
		return $ch == ' ' || $ch == '\r' || $ch == '\n' || $ch == '\t';
	}

	public static function isCloseTag($tag) {
		return $tag == 'br' || $tag == 'input' || $tag == 'link' || $tag == 'meta' || $tag == 'img';
	}

	public static function isNonHtmlTag($tag) {
		return $tag == 'script' || $tag == 'style' || $tag == 'object' || $tag == 'iframe' || $tag == 'noscript' || $tag == 'noframe';
	}

	public static function addAttr(&$attr, $name, $value) {
		$lowname = strtolower($name);
		if ($lowname == 'id') {
			$attr[$lowname] = XNode::toText($value);
		} else if ($lowname == 'class') {
			$attr[$lowname] = array();
			$classes = explode(' ', $value);
			foreach($classes as $clz) {
				if ($clz) {
					$attr[$lowname] []= XNode::toText($clz);
				}
			}
		} else if ($name) {
			$attr[XNode::toText($name)] = XNode::toText($value);
		}
	}

	public static function parse($content) {
		$root = new XNode();
		$root->tag = 'ROOT';
		$root->start = 0;
		$root->end = strlen($content);

		$queue = array();
		$current = false;

		$status = 'start';

		$len = strlen($content);
		$tag_start = 0;
		$tag = false;

		$in_quota = false;
		$quota = false;

		$attr_status = '';
		$attr_start = 0;
		$attr_name = false;
		$attr_quota = false;
		$attr = array();

		$non_html_tag = false;

		for ($i = 0; $i < $len; ++$i) {
			$ch = $content[$i];
			switch($status) {
				case 'start':
					if ($ch == '<') {
						$tag_start = $i;
						$tag = false;
						$status = 'tag';

						$attr_status = '';
						$attr_start = 0;
						$attr_name = false;
						$attr_quota = false;
						$attr = array();
					} else {
						$tag_start = $i;
						$status = 'text';
					}
					break;
				case 'tag':
					if (!$tag && (self::isSplitter($ch) || $ch == '>' || $ch == '/'  || $ch == '"' || $ch == "'")) {
						$tag = strtolower(substr($content, $tag_start + 1, $i - 1 - $tag_start));
						$attr_status = 'start';
					}

					if ($tag === false) break;

					if ($in_quota) {
						if ($ch == $quota) {
							$in_quota = false;
						}
					} else {
						if ($ch == "'" || $ch == '"') {
							$in_quota = true;
							$quota = $ch;
						}
					}
					if ($ch == '>' && !$in_quota) {
						switch ($attr_status) {
							case 'name':
								$attr_name = substr($content, $attr_start, $i - $attr_start);
								self::addAttr($attr, $attr_name, '');
								break;
							case 'eq':
								self::addAttr($attr, $attr_name, '');
								break;
							case 'value':
							case 'value_started':
								self::addAttr($attr, $attr_name, substr($content, $attr_start, $i - $attr_start));
								break;
						}

						if (substr($tag, 0, 3) == '!--') {
							if ($i - $tag_start >= 6 && substr($content, $i - 2, 2) == '--') {
								$status = 'start';
							}
							break;
						}

						if ($tag && $tag[0] == '/') {
							$tag = substr($tag, 1);
							if ($non_html_tag && $tag != $non_html_tag) {
								$status = 'start';
								break;
							}
							$find = false;
							foreach($queue as $n) {
								if ($n->tag == $tag) {
									$find = true;
								}
							}
							if ($find) {
								while($queue) {
									$node = array_pop($queue);
									$node->end = $i;
									$node->inner_end = $tag_start - 1;
									if ($node->tag == $tag) {
										break;
									}
								}
							}
							$non_html_tag = false;
							$status = 'start';
						} else {
							if (!$non_html_tag) {
								$non_html_tag = self::isNonHtmlTag($tag) ? $tag : false;
								$node = new XNode();
								$node->start = $tag_start;
								$node->tag = $tag;
								$node->inner_start = $i + 1;
								$node->attributes = $attr;
								$attr = array();
								if ($queue) {
									$node->parent = $queue[count($queue) - 1];
									$queue[count($queue) - 1]->children []= $node;
								} else {
									$node->parent = $root;
									$root->children []= $node;
								}
								if (!self::isCloseTag($tag)) {
									$queue []= $node;
								}
							}
							$status = 'start';
						}
					} else {
						switch ($attr_status) {
							case 'start':
								if (!self::isSplitter($ch) && $ch != '/') {
									$attr_start = $i;
									$attr_status = 'name';
								}
								$attr_quota = false;
								break;
							case 'name':
								if (self::isSplitter($ch)) {
									$attr_name = substr($content, $attr_start, $i - $attr_start);
									$attr_status = 'eq';
								} elseif ($ch == '=') {
									$attr_name = substr($content, $attr_start, $i - $attr_start);
									$attr_start = $i + 1;
									$attr_status = 'value';
								}
								break;
							case 'eq':
								if (self::isSplitter($ch)) {
									break;
								}
								if ($ch == '=') {
									$attr_status = 'value';
									$attr_start = $i + 1;
								} else {
									self::addAttr($attr, $attr_name, '');
									$attr_status = 'start';
								}
								break;
							case 'value':
								if (self::isSplitter($ch)) {
									break;
								}
								if ($ch == '"' || $ch == "'") {
									$attr_quota = $ch;
									$attr_start = $i + 1;
								} else {
									$attr_quota = false;
									$attr_start = $i;
								}
								$attr_status = 'value_started';
								break;
							case 'value_started':
								$end = false;
								$attr_end = $i;
								if ($attr_quota) {
									if ($ch == $attr_quota) {
										$end = true;
									}
								} else if (!$attr_quota && self::isSplitter($ch)) {
									$end = true;
								}
								if ($end) {
									self::addAttr($attr, $attr_name, substr($content, $attr_start, $attr_end - $attr_start));
									$attr_name = false;
									$attr_status = 'start';
								}
								break;
						}
					}
					break;
				case 'text':
					if ($ch == '<') {
						if (!$non_html_tag && $tag_start < $i) {
							$node = new XNode();
							$node->start = $tag_start;
							$node->end = $i - 1;
							$node->inner_start = $tag_start;
							$node->inner_end = $i - 1;
							$node->tag = 'TEXT';
							if ($queue) {
								$node->parent = $queue[count($queue) - 1];
								$queue[count($queue) - 1]->children []= $node;
							} else {
								$node->parent = $root;
								$root->children []= $node;
							}
						}

						$status = 'tag';
						$tag = false;
						$tag_start = $i;

						$attr_status = '';
						$attr_start = 0;
						$attr_name = false;
						$attr_quota = false;
						$attr = array();
					}
					break;
			}
		}
		switch($status) {
			case 'text':
				if (!$non_html_tag && $tag_start < $i && trim(substr($content, $tag_start, $i - $tag_start))) {
					$node = new XNode();
					$node->start = $tag_start;
					$node->end = $i - 1;
					$node->inner_start = $tag_start;
					$node->inner_end = $i - 1;
					$node->tag = 'TEXT';
					if ($queue) {
						$node->parent = $queue[count($queue) - 1];
						$queue[count($queue) - 1]->children []= $node;
					} else {
						$node->parent = $root;
						$root->children []= $node;
					}
				}
				break;
		}
		while ($queue) {
			$node = array_pop($queue);
			$node->end = $len - 1;
			$node->inner_end = $len - 1;
		}
		return $root;
	}

	public static function printTree($node, $prefix = false) {
		if (!$prefix) $prefix = '';
		echo $prefix, "", $node->tag, ' [', $node->attr('id'), ']', "\n";
		foreach($node->children as $child) {
			self::printTree($child, $prefix.'  ');
		}
		echo $prefix, "/", $node->tag, "\n";
	}
}