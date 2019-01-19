<?php

declare(strict_types=1);

namespace BlockHorizons\libschematic;

use pocketmine\block\Block;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use function chr;
use function file_get_contents;
use function file_put_contents;
use function ord;
use function str_repeat;
use function strlen;

class Schematic{

	/**
	 * For schematics exported from Minecraft Pocket Edition
	 */
	public const MATERIALS_POCKET = "Pocket";

	/**
	 * For schematics exported from Minecraft Alpha and newer
	 */
	public const MATERIALS_ALPHA = "Alpha";

	/**
	 * For schematics exported from Minecraft Classic
	 */
	public const MATERIALS_CLASSIC = "Classic";

	/**
	 * Fallback
	 */
	public const MATERIALS_UNKNOWN = "Unknown";

	/**
	 * Order YXZ:
	 * Height    - Along Y axis
	 * Width     - Along X axis
	 * Length    - Along Z axis
	 * @var int
	 */
	protected $height = 0, $width = 0, $length = 0;

	/** @var string */
	protected $blocks = "";
	/** @var string */
	protected $data = "";

	/** @var string */
	protected $materials = self::MATERIALS_UNKNOWN;

	/**
	 * save saves a schematic to disk.
	 *
	 * @param string $file the Schematic output file name
	 */
	public function save(string $file) : void{
		$nbt = new CompoundTag("Schematic", [
			new ByteArrayTag("Blocks", $this->blocks),
			new ByteArrayTag("Data", $this->data),
			new ShortTag("Length", $this->length),
			new ShortTag("Width", $this->width),
			new ShortTag("Height", $this->height),
			new StringTag("Materials", self::MATERIALS_POCKET)
		]);
		file_put_contents($file, (new BigEndianNbtSerializer())->writeCompressed($nbt));
	}

	/**
	 * parse parses a schematic from the file passed.
	 *
	 * @param string $file
	 */
	public function parse(string $file) : void{
		$nbt = (new BigEndianNbtSerializer())->readCompressed(file_get_contents($file));

		$this->materials = $nbt->getString("Materials");

		$this->height = $nbt->getShort("Height");
		$this->width = $nbt->getShort("Width");
		$this->length = $nbt->getShort("Length");

		$this->blocks = $nbt->getByteArray("Blocks");
		$this->data = $nbt->getByteArray("Data");
	}

	/**
	 * blocks returns a generator of blocks found in the schematic opened.
	 *
	 * @return \Generator
	 */
	public function blocks() : \Generator{
		for($x = 0; $x < $this->width; $x++){
			for($z = 0; $z < $this->length; $z++){
				for($y = 0; $y < $this->height; $y++){
					$index = $this->blockIndex($x, $y, $z);
					$id = isset($this->blocks[$index]) ? ord($this->blocks[$index]) & 0xff : 0;
					$data = isset($this->data[$index]) ? ord($this->data[$index]) & 0x0f : 0;
					$block = Block::get($id, $data);
					$block->setComponents($x, $y, $z);
					if($this->materials !== self::MATERIALS_POCKET){
						$block = $this->fixBlock($block);
					}
					yield $block;
				}
			}
		}
	}

	/**
	 * setBlocks sets a generator of blocks to a schematic, using a bounding box to calculate the size.
	 *
	 * @param $bb AxisAlignedBB
	 * @param \Generator $blocks
	 */
	public function setBlocks(AxisAlignedBB $bb, \Generator $blocks) : void{
		/** @var Block $block */
		$offset = new Vector3((int) $bb->minX, (int) $bb->minY, (int) $bb->minZ);
		$max = new Vector3((int) $bb->maxX, (int) $bb->maxY, (int) $bb->maxZ);

		$this->width = $max->x - $offset->x + 1;
		$this->length = $max->z - $offset->z + 1;
		$this->height = $max->y - $offset->y + 1;

		foreach($blocks as $block){
			$pos = $block->subtract($offset);
			$index = $this->blockIndex($pos->x, $pos->y, $pos->z);
			if(strlen($this->blocks) <= $index){
				$this->blocks .= str_repeat(chr(0), $index - strlen($this->blocks) + 1);
			}
			$this->blocks[$index] = chr($block->getId());
			$this->data[$index] = chr($block->getDamage());
		}
	}

	/**
	 * setBlockArray sets a block array to a schematic. The bounds of the schematic are calculated manually.
	 *
	 * @param Block[] $blocks
	 */
	public function setBlockArray(array $blocks) : void {
		$min = new Vector3();
		$max = new Vector3();
		foreach($blocks as $block){
			if($block->x < $min->x){
				$min->x = $block->x;
			} elseif($block->x > $max->x){
				$max->x = $block->x;
			}
			if($block->y < $min->y){
				$min->y = $block->y;
			} elseif($block->y > $max->y){
				$max->y = $block->y;
			}
			if($block->z < $min->z){
				$min->z = $block->z;
			} elseif($block->z > $max->z){
				$max->z = $block->z;
			}
		}
		$this->height = $max->y - $min->y + 1;
		$this->width = $max->x - $min->x + 1;
		$this->length = $max->z - $min->z + 1;

		foreach($blocks as $block){
			$pos = $block->subtract($min);
			$index = $this->blockIndex($pos->x, $pos->y, $pos->z);
			if(strlen($this->blocks) <= $index){
				$this->blocks .= str_repeat(chr(0), $index - strlen($this->blocks) + 1);
			}
			$this->blocks[$index] = chr($block->getId());
			$this->data[$index] = chr($block->getDamage());
		}
	}

	/**
	 * fixBlock replaces a block that has a different block ID in Pocket Edition than in PC Edition.
	 *
	 * @param $block Block
	 *
	 * @return Block
	 */
	protected function fixBlock(Block $block) : Block{
		switch($block->getId()){
			case 126:
				$replace = Block::get(Block::WOODEN_SLAB, $block->getDamage());
				break;
			case 125:
				$replace = Block::get(Block::DOUBLE_WOODEN_SLAB, $block->getDamage());
				break;
			case 188:
				$replace = Block::get(Block::FENCE, 1);
				break;
			case 189:
				$replace = Block::get(Block::FENCE, 2);
				break;
			case 190:
				$replace = Block::get(Block::FENCE, 3);
				break;
			case 191:
				$replace = Block::get(Block::FENCE, 5);
				break;
			case 192:
				$replace = Block::get(Block::FENCE, 4);
				break;
			default:
				return $block;
		}
		$replace->setComponents($block->x, $block->y, $block->z);

		return $block;
	}

	/**
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int
	 */
	protected function blockIndex(int $x, int $y, int $z) : int{
		return ($y * $this->length + $z) * $this->width + $x;
	}

	/**
	 * @return string
	 */
	public function getMaterials() : string{
		return $this->materials;
	}

	/**
	 * @param string $materials
	 *
	 * @return $this
	 */
	public function setMaterials(string $materials){
		$this->materials = $materials;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getLength() : int{
		return $this->length;
	}

	/**
	 * @return int
	 */
	public function getHeight() : int{
		return $this->height;
	}

	/**
	 * @return int
	 */
	public function getWidth() : int{
		return $this->width;
	}

	/**
	 * @param int $length
	 *
	 * @return $this
	 */
	public function setLength(int $length){
		$this->length = $length;

		return $this;
	}

	/**
	 * @param int $height
	 *
	 * @return $this
	 */
	public function setHeight(int $height){
		$this->height = $height;

		return $this;
	}

	/**
	 * @param int $width
	 *
	 * @return $this
	 */
	public function setWidth(int $width){
		$this->width = $width;

		return $this;
	}
}
