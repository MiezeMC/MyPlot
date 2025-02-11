<?php
declare(strict_types=1);
namespace MyPlot;

use muqsit\worldstyler\Selection;
use muqsit\worldstyler\shapes\CommonShape;
use muqsit\worldstyler\shapes\Cuboid;
use muqsit\worldstyler\WorldStyler;
use MyPlot\events\MyPlotClearEvent;
use MyPlot\events\MyPlotCloneEvent;
use MyPlot\events\MyPlotDisposeEvent;
use MyPlot\events\MyPlotFillEvent;
use MyPlot\events\MyPlotGenerationEvent;
use MyPlot\events\MyPlotMergeEvent;
use MyPlot\events\MyPlotResetEvent;
use MyPlot\events\MyPlotSettingEvent;
use MyPlot\events\MyPlotTeleportEvent;
use MyPlot\provider\ConfigDataProvider;
use MyPlot\provider\DataProvider;
use MyPlot\provider\EconomyProvider;
use MyPlot\provider\EconomySProvider;
use MyPlot\provider\MySQLProvider;
use MyPlot\provider\SQLiteDataProvider;
use MyPlot\task\ClearBorderTask;
use MyPlot\task\ClearPlotTask;
use MyPlot\task\FillPlotTask;
use MyPlot\task\RoadFillTask;
use onebone\economyapi\EconomyAPI;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockLegacyIds as BlockIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\lang\Language as BaseLang;
use pocketmine\math\Facing;
use pocketmine\Server;
use pocketmine\world\biome\Biome;
use pocketmine\world\biome\BiomeRegistry;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\GeneratorManagerEntry;
use pocketmine\world\World;
use pocketmine\world\World as Level;
use pocketmine\world\Position;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\WorldCreationOptions;
use pocketmine\world\WorldManager;

class MyPlot extends PluginBase
{
	private static MyPlot $instance;
	/** @var PlotLevelSettings[] $levels */
	private array $levels = [];
	private DataProvider $dataProvider;
	private ?EconomyProvider $economyProvider = null;
	private BaseLang $baseLang;

	public static function getInstance() : self {
		return self::$instance;
	}

	/**
	 * Returns the Multi-lang management class
	 *
	 * @api
	 *
	 * @return BaseLang
	 */
	public function getLanguage() : BaseLang {
		return $this->baseLang;
	}

	/**
	 * Returns the fallback language class
	 *
	 * @internal
	 *
	 * @return BaseLang
	 */
	public function getFallBackLang() : BaseLang {
		return new BaseLang(BaseLang::FALLBACK_LANGUAGE, $this->getFile() . "resources/");
	}

	/**
	 * Returns the DataProvider that is being used
	 *
	 * @api
	 *
	 * @return DataProvider
	 */
	public function getProvider() : DataProvider {
		return $this->dataProvider;
	}

	/**
	 * Returns the EconomyProvider that is being used
	 *
	 * @api
	 *
	 * @return EconomyProvider|null
	 */
	public function getEconomyProvider() : ?EconomyProvider {
		return $this->economyProvider;
	}

	/**
	 * Allows setting the economy provider to a custom provider or to null to disable economy mode
	 *
	 * @api
	 *
	 * @param EconomyProvider|null $provider
	 */
	public function setEconomyProvider(?EconomyProvider $provider) : void {
		if($provider === null) {
			$this->getConfig()->set("UseEconomy", false);
			$this->getLogger()->info("Economy mode disabled!");
		}else{
			$this->getLogger()->info("A custom economy provider has been registered. Economy mode now enabled!");
			$this->getConfig()->set("UseEconomy", true);
			$this->economyProvider = $provider;
		}
	}

	/**
	 * Returns a PlotLevelSettings object which contains all the settings of a level
	 *
	 * @api
	 *
	 * @param string $levelName
	 *
	 * @return PlotLevelSettings
	 */
	public function getLevelSettings(string $levelName) : PlotLevelSettings {
		if(!isset($this->levels[$levelName]))
			throw new AssumptionFailedError("Provided level name is not a MyPlot level");
		return $this->levels[$levelName];
	}

	/**
	 * Checks if a plot level is loaded
	 *
	 * @api
	 *
	 * @param string $levelName
	 *
	 * @return bool
	 */
	public function isLevelLoaded(string $levelName) : bool {
		return isset($this->levels[$levelName]);
	}

	/**
	 * Generate a new plot level with optional settings
	 *
	 * @api
	 *
	 * @param string $worldName
	 * @param string $generator
	 * @param mixed[] $settings
	 *
	 * @return bool
	 */
	public function generateLevel(string $worldName, string $generator = MyPlotGenerator::NAME, array $settings = []) : bool {
		$ev = new MyPlotGenerationEvent($worldName, $generator, $settings);
		$ev->call();
		$worldManager = $this->getServer()->getWorldManager();
		if($ev->isCancelled() or $worldManager->isWorldGenerated($worldName)) {
			return false;
		}
        /** @var GeneratorManagerEntry $generatorEntry */
		$generatorEntry = GeneratorManager::getInstance()->getGenerator($generator);
		if(count($settings) === 0) {
			$this->getConfig()->reload();
			$settings = $this->getConfig()->get("DefaultWorld", []);
		}
		$default = array_filter((array) $this->getConfig()->get("DefaultWorld", []), function($key) : bool {
			return !in_array($key, ["PlotSize", "GroundHeight", "RoadWidth", "RoadBlock", "WallBlock", "PlotFloorBlock", "PlotFillBlock", "BottomBlock"], true);
		}, ARRAY_FILTER_USE_KEY);
		new Config($this->getDataFolder() . "worlds" . DIRECTORY_SEPARATOR . $worldName . ".yml", Config::YAML, $default);
		$options = WorldCreationOptions::create()->setGeneratorClass($generatorEntry->getGeneratorClass())->setGeneratorOptions(json_encode($settings));
		$return = $worldManager->generateWorld($worldName, $options);
		$world = $worldManager->getWorldByName($worldName);
		if($world !== null)
			$world->setSpawnLocation(new Vector3(0, $this->getConfig()->getNested("DefaultWorld.GroundHeight", 64) + 1,0));
		return $return;
	}

	/**
	 * Saves provided plot if changed
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return bool
	 */
	public function savePlot(Plot $plot) : bool {
		return $this->dataProvider->savePlot($plot);
	}

	/**
	 * Get all the plots a player owns (in a certain level if $levelName is provided)
	 *
	 * @api
	 *
	 * @param string $username
	 * @param string $levelName
	 *
	 * @return Plot[]
	 */
	public function getPlotsOfPlayer(string $username, string $levelName) : array {
		return $this->dataProvider->getPlotsByOwner($username, $levelName);
	}

    public function setBorder(Plot $plot, Block $block)
    {
        $levelSettings = $this->getLevelSettings($plot->levelName);
		$plotPos = $this->getPlotPosition($plot);
		$plotPos->x -= 1;
		$plotPos->z -= 1;
		$level = $plotPos->world;
		$plotPos->y = $levelSettings->groundHeight;
		$plotSize = $levelSettings->plotSize;

		$xMax = $plotPos->x + $plotSize + 1;
		$yMax = $plotPos->y + 1;
		$zMax = $plotPos->z + $plotSize + 1;
		$plotPos->y += 1;

		$yResetHeight = $plotPos->y + $this->getConfig()->get('resetHeight');

		$air = VanillaBlocks::AIR();

		for($x = $plotPos->x; $x <= $xMax; $x++){
			for($y = $plotPos->y; $y <= $yResetHeight; $y++){
				$level->setBlock(new Vector3($x, $y, $plotPos->z), $air);
				$level->setBlock(new Vector3($x, $y, $zMax), $air);
			}

			for($y = $plotPos->y; $y <= $yMax; $y++){
				$level->setBlock(new Vector3($x, $y, $plotPos->z), $block);
				$level->setBlock(new Vector3($x, $y, $zMax), $block);
			}
		}

		for($z = $plotPos->z; $z < $zMax; $z++){
			for($y = $plotPos->y; $y <= $yResetHeight; $y++){
				$level->setBlock(new Vector3($plotPos->x, $y, $z), $air);
				$level->setBlock(new Vector3($xMax, $y, $z), $air);
			}

			for($y = $plotPos->y; $y <= $yMax; $y++){
				$level->setBlock(new Vector3($plotPos->x, $y, $z), $block);
				$level->setBlock(new Vector3($xMax, $y, $z), $block);
			}
		}
    }
    
    public function setWall(Plot $plot, Block $block): void
    {
        $levelSettings = $this->getLevelSettings($plot->levelName);
        $plotBeginPos = $this->getPlotPosition($plot);
        $plotBeginPos->x -= 1;
        $plotBeginPos->z -= 1;
        $world = $plotBeginPos->world;
        $plotBeginPos->y = $levelSettings->groundHeight;
        $plotSize = $levelSettings->plotSize;

        $xMax = $plotBeginPos->x + $plotSize + 1;
        $yMax = $plotBeginPos->y;
        $zMax = $plotBeginPos->z + $plotSize + 1;
        $plotBeginPos->y += 1;
        
        for($x = $plotBeginPos->x; $x <= $xMax; $x++){
            for($y = 1; $y <= $yMax; $y++){
                $world->setBlock(new Vector3($x, $y, $plotBeginPos->z), $block);
                $world->setBlock(new Vector3($x, $y, $zMax), $block);
            }
        }

        for($z = $plotBeginPos->z; $z < $zMax; $z++){
            for($y = 1; $y <= $yMax; $y++){
                $world->setBlock(new Vector3($plotBeginPos->x, $y, $z), $block);
                $world->setBlock(new Vector3($xMax, $y, $z), $block);
            }
        }
    }

	/**
	 * Get the next free plot in a level
	 *
	 * @api
	 *
	 * @param string $levelName
	 * @param int $limitXZ
	 *
	 * @return Plot|null
	 */
	public function getNextFreePlot(string $levelName, int $limitXZ = 0) : ?Plot {
		return $this->dataProvider->getNextFreePlot($levelName, $limitXZ);
	}

	/**
	 * Finds the plot at a certain position or null if there is no plot at that position
	 *
	 * @api
	 *
	 * @param Position $position
	 *
	 * @return Plot|null
	 */
	public function getPlotByPosition(Position $position) : ?Plot {
		$x = $position->x;
		$z = $position->z;
		$levelName = $position->getWorld()->getFolderName();
		if(!$this->isLevelLoaded($levelName))
			return null;
		$plotLevel = $this->getLevelSettings($levelName);

		$plot = $this->getPlotFast($x, $z, $plotLevel);
		if($plot instanceof Plot)
			return $this->dataProvider->getMergeOrigin($plot);

		if(!($basePlot = $this->dataProvider->getPlot($levelName, $x, $z))->isMerged())
			return null;

		// no plot found at current location yet, so search cardinal directions
		$plotN = $basePlot->getSide(Facing::NORTH);
		if($plotN->isSame($basePlot))
			return $this->dataProvider->getMergeOrigin($plotN);

		$plotS = $basePlot->getSide(Facing::SOUTH);
		if($plotS->isSame($basePlot))
			return $this->dataProvider->getMergeOrigin($plotS);

		$plotE = $basePlot->getSide(Facing::EAST);
		if($plotE->isSame($basePlot))
			return $this->dataProvider->getMergeOrigin($plotE);

		$plotW = $basePlot->getSide(Facing::WEST);
		if($plotW->isSame($basePlot))
			return $this->dataProvider->getMergeOrigin($plotW);

		return null;
	}

	/**
	 * @param float             $x
	 * @param float             $z
	 * @param PlotLevelSettings $plotLevel
	 *
	 * @return Plot|null
	 */
	private function getPlotFast(float &$x, float &$z, PlotLevelSettings $plotLevel) : ?Plot {
		$plotSize = $plotLevel->plotSize;
		$roadWidth = $plotLevel->roadWidth;
		$totalSize = $plotSize + $roadWidth;
		if($x >= 0) {
			$difX = $x % $totalSize;
			$x = (int) floor($x / $totalSize);
		}else{
			$difX = abs(($x - $plotSize + 1) % $totalSize);
			$x = (int) ceil(($x - $plotSize + 1) / $totalSize);
		}
		if($z >= 0) {
			$difZ = $z % $totalSize;
			$z = (int) floor($z / $totalSize);
		}else{
			$difZ = abs(($z - $plotSize + 1) % $totalSize);
			$z = (int) ceil(($z - $plotSize + 1) / $totalSize);
		}
		if(($difX > $plotSize - 1) or ($difZ > $plotSize - 1))
			return null;

		return $this->dataProvider->getPlot($plotLevel->name, $x, $z);
	}

	/**
	 * Get the beginning position of a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param bool $mergeOrigin
	 *
	 * @return Position
	 */
	public function getPlotPosition(Plot $plot, bool $mergeOrigin = true) : Position {
		$plotLevel = $this->getLevelSettings($plot->levelName);
		$origin = $this->dataProvider->getMergeOrigin($plot);
		$plotSize = $plotLevel->plotSize;
		$roadWidth = $plotLevel->roadWidth;
		$totalSize = $plotSize + $roadWidth;
		if ($mergeOrigin) {
			$x = $totalSize * $origin->X;
			$z = $totalSize * $origin->Z;
		} else {
			$x = $totalSize * $plot->X;
			$z = $totalSize * $plot->Z;
		}
		$level = $this->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		return new Position($x, $plotLevel->groundHeight, $z, $level);
	}

	/**
	 * Detects if the given position is bordering a plot
	 *
	 * @api
	 *
	 * @param Position $position
	 *
	 * @return bool
	 */
	public function isPositionBorderingPlot(Position $position) : bool {
		if(!$position->isValid())
			return false;
		for($i = Facing::NORTH; $i <= Facing::EAST; ++$i) {
			$pos = $position->getSide($i);
			$x = $pos->x;
			$z = $pos->z;
			$levelName = $pos->getWorld()->getFolderName();

			if(!$this->isLevelLoaded($levelName))
				return false;

			$plotLevel = $this->getLevelSettings($levelName);
			$plotSize = $plotLevel->plotSize;
			$roadWidth = $plotLevel->roadWidth;
			$totalSize = $plotSize + $roadWidth;
			if($x >= 0) {
				$difX = $x % $totalSize;
			}else{
				$difX = abs(($x - $plotSize + 1) % $totalSize);
			}
			if($z >= 0) {
				$difZ = $z % $totalSize;
			}else{
				$difZ = abs(($z - $plotSize + 1) % $totalSize);
			}
			if(($difX > $plotSize - 1) or ($difZ > $plotSize - 1)) {
				continue;
			}
			return true;
		}
		for($i = Facing::NORTH; $i <= Facing::EAST; ++$i) {
			for($n = Facing::NORTH; $n <= Facing::EAST; ++$n) {
				if($i === $n or Facing::opposite($i) === $n)
					continue;
				$pos = $position->getSide($i)->getSide($n);
				$x = $pos->x;
				$z = $pos->z;
				$levelName = $pos->getWorld()->getFolderName();

				$plotLevel = $this->getLevelSettings($levelName);
				$plotSize = $plotLevel->plotSize;
				$roadWidth = $plotLevel->roadWidth;
				$totalSize = $plotSize + $roadWidth;
				if($x >= 0) {
					$difX = $x % $totalSize;
				}else{
					$difX = abs(($x - $plotSize + 1) % $totalSize);
				}
				if($z >= 0) {
					$difZ = $z % $totalSize;
				}else{
					$difZ = abs(($z - $plotSize + 1) % $totalSize);
				}
				if(($difX > $plotSize - 1) or ($difZ > $plotSize - 1)) {
					continue;
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Retrieves the plot adjacent to teh given position
	 *
	 * @api
	 *
	 * @param Position $position
	 *
	 * @return Plot|null
	 */
	public function getPlotBorderingPosition(Position $position) : ?Plot {
		if(!$position->isValid())
			return null;
		for($i = Facing::NORTH; $i <= Facing::EAST; ++$i) {
			$pos = $position->getSide($i);
			$x = $pos->x;
			$z = $pos->z;
			$levelName = $pos->getWorld()->getFolderName();

			if(!$this->isLevelLoaded($levelName))
				return null;

			$plotLevel = $this->getLevelSettings($levelName);
			$plotSize = $plotLevel->plotSize;
			$roadWidth = $plotLevel->roadWidth;
			$totalSize = $plotSize + $roadWidth;
			if($x >= 0) {
				$X = (int) floor($x / $totalSize);
				$difX = $x % $totalSize;
			}else{
				$X = (int) ceil(($x - $plotSize + 1) / $totalSize);
				$difX = abs(($x - $plotSize + 1) % $totalSize);
			}
			if($z >= 0) {
				$Z = (int) floor($z / $totalSize);
				$difZ = $z % $totalSize;
			}else{
				$Z = (int) ceil(($z - $plotSize + 1) / $totalSize);
				$difZ = abs(($z - $plotSize + 1) % $totalSize);
			}
			if(($difX > $plotSize - 1) or ($difZ > $plotSize - 1)) {
				if($this->getPlotByPosition($pos) instanceof Plot) {
					return $this->getPlotByPosition($pos);
				}
				continue;
			}
			return $this->dataProvider->getPlot($levelName, $X, $Z);
		}
		return null;
	}

	/**
	 * Returns the AABB of the plot area
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return AxisAlignedBB
	 */
	public function getPlotBB(Plot $plot) : AxisAlignedBB {
		$plotLevel = $this->getLevelSettings($plot->levelName);
		$plotSize = $plotLevel->plotSize-1;
		$pos = $this->getPlotPosition($plot, false);
		$xMax = (int)($pos->x + $plotSize);
		$zMax = (int)($pos->z + $plotSize);
		foreach ($this->dataProvider->getMergedPlots($plot) as $mergedPlot){
			$xplot = $this->getPlotPosition($mergedPlot, false)->x;
			$zplot = $this->getPlotPosition($mergedPlot, false)->z;
			$xMaxPlot = (int)($xplot + $plotSize);
			$zMaxPlot = (int)($zplot + $plotSize);
			if($pos->x > $xplot) $pos->x = $xplot;
			if($pos->z > $zplot) $pos->z = $zplot;
			if($xMax < $xMaxPlot) $xMax = $xMaxPlot;
			if($zMax < $zMaxPlot) $zMax = $zMaxPlot;
		}

		return new AxisAlignedBB(
			min($pos->x, $xMax),
			0,
			min($pos->z, $zMax),
			max($pos->x, $xMax),
			$pos->getWorld()->getMaxY(),
			max($pos->z, $zMax)
		);
	}

	/**
	 * @param Plot $plot The plot that is to be expanded
	 * @param int  $direction The Vector3 direction value to expand towards
	 * @param int  $maxBlocksPerTick
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function mergePlots(Plot $plot, int $direction, int $maxBlocksPerTick = 1024) : bool {
		if (!$this->isLevelLoaded($plot->levelName))
			return false;
		/** @var Plot[][] $toMerge */
		$toMerge = [];
		$mergedPlots = $this->getProvider()->getMergedPlots($plot);
		$newPlot = $plot->getSide($direction);
		$alreadyMerged = false;
		foreach ($mergedPlots as $mergedPlot) {
			if ($mergedPlot->isSame($newPlot)) {
				$alreadyMerged = true;
			}
		}
		if ($alreadyMerged === false and $newPlot->isMerged()) {
			$this->getLogger()->debug("Failed to merge due to plot origin mismatch");
			return false;
		}
		$toMerge[] = [$plot, $newPlot];

		foreach ($mergedPlots as $mergedPlot) {
			$newPlot = $mergedPlot->getSide($direction);
			$alreadyMerged = false;
			foreach ($mergedPlots as $mergedPlot2) {
				if ($mergedPlot2->isSame($newPlot)) {
					$alreadyMerged = true;
				}
			}
			if ($alreadyMerged === false and $newPlot->isMerged()) {
				$this->getLogger()->debug("Failed to merge due to plot origin mismatch");
				return false;
			}
			$toMerge[] = [$mergedPlot, $newPlot];
		}
		/** @var Plot[][] $toFill */
		$toFill = [];
		foreach ($toMerge as $pair) {
			foreach ($toMerge as $pair2) {
				for ($i = Facing::NORTH; $i <= Facing::EAST; ++$i) {
					if ($pair[1]->getSide($i)->isSame($pair2[1])) {
						$toFill[] = [$pair[1], $pair2[1]];
					}
				}
			}
		}
		$ev = new MyPlotMergeEvent($this->getProvider()->getMergeOrigin($plot), $toMerge);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		foreach ($toMerge as $pair) {
			if ($pair[1]->owner === "") {
				$this->getLogger()->debug("Failed to merge due to plot not claimed");
				return false;
			} elseif ($plot->owner !== $pair[1]->owner) {
				$this->getLogger()->debug("Failed to merge due to owner mismatch");
				return false;
			}
		}

		// TODO: WorldStyler clearing

		foreach ($toMerge as $pair)
			$this->getScheduler()->scheduleTask(new RoadFillTask($this, $pair[0], $pair[1], false, -1, $maxBlocksPerTick));

		foreach ($toFill as $pair)
			$this->getScheduler()->scheduleTask(new RoadFillTask($this, $pair[0], $pair[1], true, $direction, $maxBlocksPerTick));

		return $this->getProvider()->mergePlots($this->getProvider()->getMergeOrigin($plot), ...array_map(function (array $val) : Plot {
			return $val[1];
		}, $toMerge));
	}

	/**
	 * Teleport a player to a plot
	 *
	 * @api
	 *
	 * @param Player $player
	 * @param Plot $plot
	 * @param bool $center
	 *
	 * @return bool
	 */
	public function teleportPlayerToPlot(Player $player, Plot $plot, bool $center = false) : bool {
		$ev = new MyPlotTeleportEvent($plot, $player, $center);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		if($plot->isMerged()){
			return $this->teleportPlayerToMerge($player, $plot, $center);
		}
		if($center)
			return $this->teleportMiddle($player, $plot);
		$plotWorld = $this->getLevelSettings($plot->levelName);
		$pos = $this->getPlotPosition($plot);
		$pos->x += floor($plotWorld->plotSize / 2);
		$pos->y += 1.5;
		$pos->z -= 1;
		$world = Server::getInstance()->getWorldManager()->getWorldByName($plot->levelName);
		if($world->getOrLoadChunkAtPosition($pos) === null) {
			$world->orderChunkPopulation($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4, null)->onCompletion(function ()use($player, $pos): void{
			    if($player->isOnline()) $player->teleport($pos);
            }, function (): void{});
			return true;
		}

		return $player->teleport($pos);
	}

	/**
	 * Teleport a player to a Merge
	 *
	 * @api
	 *
	 * @param Player $player
	 * @param Plot $plot
	 * @param bool $center
	 *
	 * @return bool
	 */
	public function teleportPlayerToMerge(Player $player, Plot $plot, bool $center = false) : bool {
		$ev = new MyPlotTeleportEvent($plot, $player, $center);
		$ev->call();
		if ($ev->isCancelled()) {
			return false;
		}
		if(!$plot->isMerged()){
			$this->teleportPlayerToPlot($player, $plot, $center);
		}
		if ($center)
			return $this->teleportMiddle($player, $plot);
		$plotLevel = $this->getLevelSettings($plot->levelName);
		$mergedPlots = $this->getProvider()->getMergedPlots($plot);
		$minx = $this->getPlotPosition(array_reduce($mergedPlots, function(Plot $a, Plot $b) : Plot {
			return $this->getPlotPosition($a, false)->x < $this->getPlotPosition($b, false)->x ? $a : $b;
		}, $mergedPlots[0]), false)->x;
		$maxx = $this->getPlotPosition(array_reduce($mergedPlots, function(Plot $a, Plot $b) : Plot {
				return $this->getPlotPosition($a, false)->x > $this->getPlotPosition($b, false)->x ? $a : $b;
			}, $mergedPlots[0]), false)->x + $plotLevel->plotSize;
		$minz = $this->getPlotPosition(array_reduce($mergedPlots, function(Plot $a, Plot $b) : Plot {
			return $this->getPlotPosition($a, false)->z < $this->getPlotPosition($b, false)->z ? $a : $b;
		}, $mergedPlots[0]), false)->z;

		$pos = new Position($minx,$plotLevel->groundHeight, $minz, $this->getServer()->getWorldManager()->getWorldByName($plot->levelName));
		$pos->x = floor(($minx + $maxx) / 2);
		$pos->y += 1.5;
		$pos->z -= 1;
		return $player->teleport($pos);
	}

	/**
	 * Claims a plot in a players name
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param string $claimer
	 * @param string $plotName
	 *
	 * @return bool
	 */
	public function claimPlot(Plot $plot, string $claimer, string $plotName = "") : bool {
		$newPlot = clone $plot;
		$newPlot->owner = $claimer;
		$newPlot->price = 0.0;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		$plot = $ev->getPlot();
		$failed = false;
		foreach($this->getProvider()->getMergedPlots($plot) as $merged) {
			if($plotName !== "") {
				$this->renamePlot($merged, $plotName);
			}
			$merged->owner = $claimer;
			$merged->price = 0.0;
			if(!$this->savePlot($merged))
				$failed = true;
		}
		return !$failed;
	}

	/**
	 * Renames a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param string $newName
	 *
	 * @return bool
	 */
	public function renamePlot(Plot $plot, string $newName = "") : bool {
		$newPlot = clone $plot;
		$newPlot->name = $newName;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	/**
	 * Clones a plot to another location
	 *
	 * @api
	 *
	 * @param Plot $plotFrom
	 * @param Plot $plotTo
	 *
	 * @return bool
	 */
	public function clonePlot(Plot $plotFrom, Plot $plotTo) : bool {
		$styler = $this->getServer()->getPluginManager()->getPlugin("WorldStyler");
		if(!$styler instanceof WorldStyler) {
			return false;
		}
		if(!$this->isLevelLoaded($plotFrom->levelName) or !$this->isLevelLoaded($plotTo->levelName)) {
			return false;
		}
		$aabb = $this->getPlotBB($plotTo);
		$world = $this->getServer()->getWorldManager()->getWorldByName($plotTo->levelName);
		if($world === null)
		    return false;
		foreach($this->getPlotChunks($plotTo) as $id => $chunk){
		    $coords = explode(';', $id);
		    foreach($world->getChunkEntities((int)$coords[0], (int)$coords[1]) as $entity) {
				if($aabb->isVectorInXZ($entity->getPosition())) {
					if($entity instanceof Player){
						$this->teleportPlayerToPlot($entity, $plotTo);
					}
				}
			}
		}
		$ev = new MyPlotCloneEvent($plotFrom, $plotTo);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		$plotFrom = $ev->getPlot();
		$plotTo = $ev->getClonePlot();
		if(!$this->isLevelLoaded($plotFrom->levelName) or !$this->isLevelLoaded($plotTo->levelName)) {
			return false;
		}
		$plotLevel = $this->getLevelSettings($plotFrom->levelName);
		$plotSize = $plotLevel->plotSize-1;
		$plotBeginPos = $this->getPlotPosition($plotFrom);
		$level = $plotBeginPos->getWorld();
		$plotBeginPos = $plotBeginPos->subtract(1, 0, 1);
		$plotBeginPos->y = 0;
		$xMax = (int)($plotBeginPos->x + $plotSize);
		$zMax = (int)($plotBeginPos->z + $plotSize);
		foreach ($this->getProvider()->getMergedPlots($plotFrom) as $mergedPlot){
			$pos = $this->getPlotPosition($mergedPlot, false)->subtract(1,0,1);
			$xMaxPlot = (int)($pos->x + $plotSize);
			$zMaxPlot = (int)($pos->z + $plotSize);
			if($plotBeginPos->x > $pos->x) $plotBeginPos->x = $pos->x;
			if($plotBeginPos->z > $pos->z) $plotBeginPos->z = $pos->z;
			if($xMax < $xMaxPlot) $xMax = $xMaxPlot;
			if($zMax < $zMaxPlot) $zMax = $zMaxPlot;
		}
		$selection = $styler->getSelection(99997) ?? new Selection(99997);
		$selection->setPosition(1, $plotBeginPos);
		$vec2 = new Vector3($xMax + 1, $level->getMaxY() - 1, $zMax + 1);
		$selection->setPosition(2, $vec2);
		$cuboid = Cuboid::fromSelection($selection);
		//$cuboid = $cuboid->async(); // do not use async because WorldStyler async is very broken right now
		$cuboid->copy($level, $vec2, function (float $time, int $changed) : void {
			$this->getLogger()->debug(TF::GREEN . 'Copied ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's to the MyPlot clipboard.');
		});

		$plotLevel = $this->getLevelSettings($plotTo->levelName);
		$plotSize = $plotLevel->plotSize-1;
		$plotBeginPos = $this->getPlotPosition($plotTo);
		$level = $plotBeginPos->getWorld();
		$plotBeginPos = $plotBeginPos->subtract(1, 0, 1);
		$plotBeginPos->y = 0;
		$xMax = (int)($plotBeginPos->x + $plotSize);
		$zMax = (int)($plotBeginPos->z + $plotSize);
		foreach ($this->getProvider()->getMergedPlots($plotTo) as $mergedPlot){
			$pos = $this->getPlotPosition($mergedPlot, false)->subtract(1,0,1);
			$xMaxPlot = (int)($pos->x + $plotSize);
			$zMaxPlot = (int)($pos->z + $plotSize);
			if($plotBeginPos->x > $pos->x) $plotBeginPos->x = $pos->x;
			if($plotBeginPos->z > $pos->z) $plotBeginPos->z = $pos->z;
			if($xMax < $xMaxPlot) $xMax = $xMaxPlot;
			if($zMax < $zMaxPlot) $zMax = $zMaxPlot;
		}
		$selection->setPosition(1, $plotBeginPos);
		$vec2 = new Vector3($xMax + 1, $level->getMaxY() - 1, $zMax + 1);
		$selection->setPosition(2, $vec2);
		$commonShape = CommonShape::fromSelection($selection);
		//$commonShape = $commonShape->async(); // do not use async because WorldStyler async is very broken right now
		$commonShape->paste($level, $vec2, true, function (float $time, int $changed) : void {
			$this->getLogger()->debug(TF::GREEN . 'Pasted ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's from the MyPlot clipboard.');
		});
		$styler->removeSelection(99997);
		foreach($this->getPlotChunks($plotTo) as $id => $chunk) {
			$coords = explode(';', $id);
			$level->setChunk((int)$coords[0], (int)$coords[1], $chunk);
		}
		return true;
	}

	/**
	 * Reset all the blocks inside a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param int $maxBlocksPerTick
	 *
	 * @return bool
	 */
	public function clearPlot(Plot $plot, int $maxBlocksPerTick = 1024) : bool {
		$ev = new MyPlotClearEvent($plot, $maxBlocksPerTick);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		$plot = $ev->getPlot();
		if(!$this->isLevelLoaded($plot->levelName)) {
			return false;
		}
		$maxBlocksPerTick = $ev->getMaxBlocksPerTick();
		$level = $this->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		if($level === null)
			return false;

        $this->removeRating($plot);

		foreach($level->getEntities() as $entity) {
			if($this->getPlotBB($plot)->isVectorInXZ($entity->getPosition())) {
				if(!$entity instanceof Player) {
					$entity->flagForDespawn();
				}else{
					$this->teleportPlayerToPlot($entity, $plot);
				}
			}
		}
		if($this->getConfig()->get("FastClearing", false) === true) {
			$styler = $this->getServer()->getPluginManager()->getPlugin("WorldStyler");
			if(!$styler instanceof WorldStyler) {
				return false;
			}
			$plotLevel = $this->getLevelSettings($plot->levelName);
			$plotSize = $plotLevel->plotSize-1;
			$plotBeginPos = $this->getPlotPosition($plot);
			$xMax = (int)($plotBeginPos->x + $plotSize);
			$zMax = (int)($plotBeginPos->z + $plotSize);
			foreach ($this->getProvider()->getMergedPlots($plot) as $mergedPlot){
				$xplot = $this->getPlotPosition($mergedPlot, false)->x;
				$zplot = $this->getPlotPosition($mergedPlot, false)->z;
				$xMaxPlot = (int)($xplot + $plotSize);
				$zMaxPlot = (int)($zplot + $plotSize);
				if($plotBeginPos->x > $xplot) $plotBeginPos->x = $xplot;
				if($plotBeginPos->z > $zplot) $plotBeginPos->z = $zplot;
				if($xMax < $xMaxPlot) $xMax = $xMaxPlot;
				if($zMax < $zMaxPlot) $zMax = $zMaxPlot;
			}
			// Above ground
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$plotBeginPos->y = $plotLevel->groundHeight+1;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($xMax, Level::Y_MAX, $zMax));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->getWorld(), VanillaBlocks::AIR()->getId(), function (float $time, int $changed) : void {
				$this->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			// Ground Surface
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$plotBeginPos->y = $plotLevel->groundHeight;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($xMax, $plotLevel->groundHeight, $zMax));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->getWorld(), $plotLevel->plotFloorBlock->getFullId(), function (float $time, int $changed) : void {
				$this->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			// Ground
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$plotBeginPos->y = 1;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($xMax, $plotLevel->groundHeight-1, $zMax));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->getWorld(), $plotLevel->plotFillBlock->getFullId(), function (float $time, int $changed) : void {
				$this->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			// Bottom of world
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$plotBeginPos->y = 0;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($xMax, 0, $zMax));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->getWorld(), $plotLevel->bottomBlock->getFullId(), function (float $time, int $changed) : void {
				$this->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			foreach($this->getPlotChunks($plot) as $id => $chunk) {
                $coords = explode(';', $id);
                $level->setChunk((int)$coords[0], (int)$coords[1], $chunk);
            }
			$this->getScheduler()->scheduleDelayedTask(new ClearBorderTask($this, $plot), 1);
			return true;
		}
		$this->getScheduler()->scheduleTask(new ClearPlotTask($this, $plot, $maxBlocksPerTick));
		return true;
	}

    public function getRatingConfig(): Config
    {
        return new Config($this->getDataFolder() . "ratings.yml", Config::YAML);
    }

    private function buildRatingString($x, $z, string $worldName): string
    {
        return (string)$x . "|" . (string)$z . "|" . $worldName;
    }

    public function ratePlot(Plot $plot, int $rating): void
    {
        $cfg = $this->getRatingConfig();

        if ($rating < 0 || $rating > 5) return;

        $str = $this->buildRatingString($plot->X, $plot->Z, $plot->levelName);

        $cfg->set($str, $rating);
        $cfg->save();
    }

    public function removeRating(Plot $plot): bool
    {
        $cfg = $this->getRatingConfig();
        $str = $this->buildRatingString($plot->X, $plot->Z, $plot->levelName);

        if (!$cfg->exists($str)) return false;

        $cfg->remove($str);
        $cfg->save();
        return true;
    }

    public function getRating(Plot $plot): int|null
    {
        $cfg = $this->getRatingConfig();
        $str = $this->buildRatingString($plot->X, $plot->Z, $plot->levelName);

        if (!$cfg->exists($str)) return null;

        return (int)$cfg->get($str);
    }

	/**
	 * Fills the whole plot with a block
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param Block $plotFillBlock
	 * @param int $maxBlocksPerTick
	 *
	 * @return bool
	 */
	public function fillPlot(Plot $plot, Block $plotFillBlock, int $maxBlocksPerTick = 1024) : bool {
		$ev = new MyPlotFillEvent($plot, $maxBlocksPerTick);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		$plot = $ev->getPlot();
		if(!$this->isLevelLoaded($plot->levelName)) {
			return false;
		}
		$maxBlocksPerTick = $ev->getMaxBlocksPerTick();
		foreach($this->getServer()->getWorldManager()->getWorldByName($plot->levelName)->getEntities() as $entity) {
			if($this->getPlotBB($plot)->isVectorInXZ($entity->getPosition()) && $entity->getPosition()->getY() <= $this->getLevelSettings($plot->levelName)->groundHeight) {
				if(!$entity instanceof Player) {
					$entity->flagForDespawn();
				}else{
					$this->teleportPlayerToPlot($entity, $plot);
				}
			}
		}
		if($this->getConfig()->get("FastFilling", false) === true) {
			$styler = $this->getServer()->getPluginManager()->getPlugin("WorldStyler");
			if(!$styler instanceof WorldStyler) {
				return false;
			}
			$plotLevel = $this->getLevelSettings($plot->levelName);
			$plotSize = $plotLevel->plotSize-1;
			$plotBeginPos = $this->getPlotPosition($plot);
			// Ground
			$selection = $styler->getSelection(99998);
			$plotBeginPos->y = 1;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($plotBeginPos->x + $plotSize, $plotLevel->groundHeight, $plotBeginPos->z + $plotSize));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->world, $plotFillBlock->getFullId(), function (float $time, int $changed) : void {
				$this->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			// Bottom of world
			$selection = $styler->getSelection(99998);
			$plotBeginPos->y = 0;
			$selection->setPosition(1, $plotBeginPos);
			$selection->setPosition(2, new Vector3($plotBeginPos->x + $plotSize, 0, $plotBeginPos->z + $plotSize));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($plotBeginPos->world, $plotLevel->bottomBlock->getFullId(), function (float $time, int $changed) : void {
				$this->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);
			foreach($this->getPlotChunks($plot) as $id => $chunk) {
                $coords = explode(';', $id);
                $plotBeginPos->getWorld()?->setChunk((int)$coords[0], (int)$coords[1], $chunk);
            }
			return true;
		}
		$this->getScheduler()->scheduleTask(new FillPlotTask($this, $plot, $plotFillBlock, $maxBlocksPerTick));
		return true;
	}

	/**
	 * Delete the plot data
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return bool
	 */
	public function disposePlot(Plot $plot) : bool {
		$ev = new MyPlotDisposeEvent($plot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->getProvider()->deletePlot($plot);
	}

	/**
	 * Clear and dispose a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param int $maxBlocksPerTick
	 *
	 * @return bool
	 */
	public function resetPlot(Plot $plot, int $maxBlocksPerTick = 1024) : bool {
		$ev = new MyPlotResetEvent($plot);
		$ev->call();
		if($ev->isCancelled())
			return false;
		if($this->disposePlot($plot)) {
			return $this->clearPlot($plot, $maxBlocksPerTick);
		}
		return false;
	}

	 /* @api
	 *
	 * @param Plot $plot
	 * @param Biome $biome
	 *
	 * @return bool
	 */
	public function setPlotBiome(Plot $plot, Biome $biome) : bool {
		$newPlot = clone $plot;
		$newPlot->biome = str_replace(" ", "_", strtoupper($biome->getName()));
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		$plot = $ev->getPlot();
		if(defined(BiomeIds::class."::".$plot->biome) and is_int(constant(BiomeIds::class."::".$plot->biome))) {
			$biome = constant(BiomeIds::class."::".$plot->biome);
		}else{
			$biome = BiomeIds::PLAINS;
		}
		$biome = BiomeRegistry::getInstance()->getBiome($biome);
		if(!$this->isLevelLoaded($plot->levelName))
			return false;
		$failed = false;
		foreach($this->getProvider()->getMergedPlots($plot) as $merged) {
			$merged->biome = $plot->biome;
			if(!$this->savePlot($merged))
				$failed = true;
		}
		$plotWorld = $this->getLevelSettings($plot->levelName);
		$world = $this->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		$chunks = $this->getPlotChunks($plot);
		foreach($chunks as $id => $chunk) {
			$coords = explode(';', $id);
			for($x = 0; $x < 16; ++$x) {
				for($z = 0; $z < 16; ++$z) {
					$chunkPlot = $this->getPlotByPosition(new Position((((int)$coords[0]) << 4) + $x, $plotWorld->groundHeight, (((int)$coords[1]) << 4) + $z, $world));
					if($chunkPlot instanceof Plot and $chunkPlot->isSame($plot)) {
						$chunk->setBiomeId($x, $z, $biome->getId());
					}
				}
			}
			$world->setChunk((int)$coords[0], (int)$coords[1], $chunk, false);
		}
		return !$failed;
	}

	public function setPlotPvp(Plot $plot, bool $pvp) : bool {
		$newPlot = clone $plot;
		$newPlot->pvp = $pvp;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	public function addPlotHelper(Plot $plot, string $player) : bool {
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
        if (!$newPlot->addHelper($player)) {
            $ev->cancel();
        } else {
            $ev->uncancel();
        }
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	public function removePlotHelper(Plot $plot, string $player) : bool {
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
        if (!$newPlot->removeHelper($player)) {
            $ev->cancel();
        } else {
            $ev->uncancel();
        }
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	public function addPlotDenied(Plot $plot, string $player) : bool {
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		if (!$newPlot->denyPlayer($player)) {
            $ev->cancel();
        } else {
            $ev->uncancel();
        }
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	public function removePlotDenied(Plot $plot, string $player) : bool {
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		if (!$newPlot->unDenyPlayer($player)) {
            $ev->cancel();
        } else {
            $ev->uncancel();
        }
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		return $this->savePlot($ev->getPlot());
	}

	/**
	 * Assigns a price to a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param float $price
	 *
	 * @return bool
	 */
	public function sellPlot(Plot $plot, float $price) : bool {
		if($this->getEconomyProvider() === null or $price < 0)
			return false;

		$newPlot = clone $plot;
		$newPlot->price = $price;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()) {
			return false;
		}
		$plot = $ev->getPlot();
		return $this->savePlot($plot);
	}

	/**
	 * Resets the price, adds the money to the player's account and claims a plot in a players name
	 *
	 * @api
	 *
	 * @param Plot $plot
	 * @param Player $player
	 *
	 * @return bool
	 */
	public function buyPlot(Plot $plot, Player $player) : bool {
		if($this->getEconomyProvider() === null or !$this->getEconomyProvider()->reduceMoney($player, $plot->price) or !$this->getEconomyProvider()->addMoney($this->getServer()->getOfflinePlayer($plot->owner), $plot->price))
			return false;
		$failed = false;
		foreach ($this->dataProvider->getMergedPlots($plot) as $mergedPlot) {
			$newPlot = clone $mergedPlot;
			$newPlot->owner = $player->getName();
			$newPlot->helpers = [];
			$newPlot->denied = [];
			$newPlot->price = 0.0;
			$ev = new MyPlotSettingEvent($mergedPlot, $newPlot);
			$ev->call();
			if ($ev->isCancelled()) {
				return false;
			}
			$mergedPlot = $ev->getPlot();
			if($this->savePlot($mergedPlot))
				$failed = true;
		}
		return !$failed;
	}

	/**
	 * Returns the PlotLevelSettings of all the loaded levels
	 *
	 * @api
	 *
	 * @return PlotLevelSettings[]
	 */
	public function getPlotLevels() : array {
		return $this->levels;
	}

	/**
	 * Returns the Chunks contained in a plot
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return Chunk[]
	 */
	public function getPlotChunks(Plot $plot) : array {
		if(!$this->isLevelLoaded($plot->levelName))
			return [];
		$plotLevel = $this->getLevelSettings($plot->levelName);
		$level = $this->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		if($level === null)
			return [];
		$plotSize = $plotLevel->plotSize;
		$chunks = [];
		foreach ($this->dataProvider->getMergedPlots($plot) as $mergedPlot){
			$pos = $this->getPlotPosition($mergedPlot, false);
			$xMax = ($pos->x + $plotSize) >> 4;
			$zMax = ($pos->z + $plotSize) >> 4;
			for($x = $pos->x >> 4; $x <= $xMax; $x++) {
				for($z = $pos->z >> 4; $z <= $zMax; $z++) {
					$chunks[] = $level->getChunk($x, $z, true);
				}
			}
		}
		return $chunks;
	}

	/**
	 * Get the maximum number of plots a player can claim
	 *
	 * @api
	 *
	 * @param Player $player
	 *
	 * @return int
	 */
	public function getMaxPlotsOfPlayer(Player $player) : int {
		if($player->hasPermission("myplot.claimplots.unlimited"))
			return PHP_INT_MAX;
		/** @var Permission[] $perms */
		$perms = array_merge(PermissionManager::getInstance()->getPermissions(), $player->getEffectivePermissions());
		$perms = array_filter($perms, function(string $name) : bool {
			return (str_starts_with($name, "myplot.claimplots."));
		}, ARRAY_FILTER_USE_KEY);
		if(count($perms) === 0)
			return 0;
		krsort($perms, SORT_FLAG_CASE | SORT_NATURAL);
		/**
		 * @var string $name
		 * @var Permission $perm
		 */
		foreach($perms as $name => $perm) {
			$maxPlots = substr($name, 18);
			if(is_numeric($maxPlots)) {
				return (int) $maxPlots;
			}
		}
		return 0;
	}

	/**
	 * Finds the exact center of the plot at ground level
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return Position|null
	 */
	public function getPlotMid(Plot $plot) : ?Position {
		if(!$this->isLevelLoaded($plot->levelName))
			return null;
		$plotLevel = $this->getLevelSettings($plot->levelName);
		$plotSize = $plotLevel->plotSize;
		$pos = $this->getPlotPosition($plot);
		return new Position($pos->x + ($plotSize / 2), $pos->y + 1, $pos->z + ($plotSize / 2), $pos->getWorld());
	}

	/**
	 * Finds the exact center of the Merge at ground level
	 *
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return Position|null
	 */
	public function getMergeMid(Plot $plot) : ?Position {
		$plotLevel = $this->getLevelSettings($plot->levelName);
		$plotSize = $plotLevel->plotSize;
		$mergedPlots = $this->getProvider()->getMergedPlots($plot);
		$minx = $this->getPlotPosition(array_reduce($mergedPlots, function(Plot $a, Plot $b) : Plot {
			return $this->getPlotPosition($a, false)->x < $this->getPlotPosition($b, false)->x ? $a : $b;
		}, $mergedPlots[0]), false)->x;
		$maxx = $this->getPlotPosition(array_reduce($mergedPlots, function(Plot $a, Plot  $b) : Plot {
				return $this->getPlotPosition($a, false)->x > $this->getPlotPosition($b, false)->x ? $a : $b;
			}, $mergedPlots[0]), false)->x + $plotSize;
		$minz = $this->getPlotPosition(array_reduce($mergedPlots, function(Plot $a, Plot $b) : Plot {
			return $this->getPlotPosition($a, false)->z < $this->getPlotPosition($b, false)->z ? $a : $b;
		}, $mergedPlots[0]), false)->z;
		$maxz = $this->getPlotPosition(array_reduce($mergedPlots, function(Plot $a, Plot $b) : Plot {
				return $this->getPlotPosition($a, false)->z > $this->getPlotPosition($b, false)->z ? $a : $b;
			}, $mergedPlots[0]), false)->z + $plotSize;
		return new Position(($minx + $maxx) / 2, $plotLevel->groundHeight, ($minz + $maxz) / 2, $this->getServer()->getWorldManager()->getWorldByName($plot->levelName));
	}

	/**
	 * Teleports the player to the exact center of the plot at nearest open space to the ground level
	 *
	 * @internal
	 *
	 * @param Plot $plot
	 * @param Player $player
	 *
	 * @return bool
	 */
	private function teleportMiddle(Player $player, Plot $plot) : bool {
		if($plot->isMerged()){
			$mid = $this->getMergeMid($plot);
		}else {
			$mid = $this->getPlotMid($plot);
		}
		if ($mid === null) {
			return false;
		}
		return $player->teleport($mid);
	}

	/* -------------------------- Non-API part -------------------------- */
	public function onLoad() : void {
		self::$instance = $this;
		$this->getLogger()->debug(TF::BOLD . "Loading Configs");
		$this->reloadConfig();
		@mkdir($this->getDataFolder() . "worlds");
		$this->getLogger()->debug(TF::BOLD . "Loading MyPlot Generator");
		GeneratorManager::getInstance()->addGenerator(MyPlotGenerator::class, "myplot", fn() => null);
		$this->getLogger()->debug(TF::BOLD . "Loading Languages");
		// Loading Languages
		/** @var string $lang */
		$lang = $this->getConfig()->get("Language", BaseLang::FALLBACK_LANGUAGE);
		if($this->getConfig()->get("Custom Messages", false) === true) {
			if(!file_exists($this->getDataFolder()."lang.ini")) {
				/** @var string|resource $resource */
				$resource = $this->getResource($lang.".ini") ?? file_get_contents($this->getFile()."resources/".BaseLang::FALLBACK_LANGUAGE.".ini");
				file_put_contents($this->getDataFolder()."lang.ini", $resource);
				if(!is_string($resource)) {
					/** @var resource $resource */
					fclose($resource);
				}
				$this->saveResource(BaseLang::FALLBACK_LANGUAGE.".ini", true);
				$this->getLogger()->debug("Custom Language ini created");
			}
			$this->baseLang = new BaseLang("lang", $this->getDataFolder());
		}else{
			if(file_exists($this->getDataFolder()."lang.ini")) {
				unlink($this->getDataFolder()."lang.ini");
				unlink($this->getDataFolder().BaseLang::FALLBACK_LANGUAGE.".ini");
				$this->getLogger()->debug("Custom Language ini deleted");
			}
			$this->baseLang = new BaseLang($lang, $this->getFile() . "resources/");
		}
		$this->getLogger()->debug(TF::BOLD . "Loading Data Provider settings");
		// Initialize DataProvider
		/** @var int $cacheSize */
		$cacheSize = $this->getConfig()->get("PlotCacheSize", 1024);
		$dataProvider = $this->getConfig()->get("DataProvider", "sqlite3");
		if(!is_string($dataProvider))
			$this->dataProvider = new ConfigDataProvider($this, $cacheSize);
		else
			try {
				switch(strtolower($dataProvider)) {
					case "mysqli":
					case "mysql":
						if(extension_loaded("mysqli")) {
							$settings = (array) $this->getConfig()->get("MySQLSettings");
							$this->dataProvider = new MySQLProvider($this, $cacheSize, $settings);
						}else {
							$this->getLogger()->warning("MySQLi is not installed in your php build! JSON will be used instead.");
							$this->dataProvider = new ConfigDataProvider($this, $cacheSize);
						}
					break;
					case "yaml":
						if(extension_loaded("yaml")) {
							$this->dataProvider = new ConfigDataProvider($this, $cacheSize, true);
						}else {
							$this->getLogger()->warning("YAML is not installed in your php build! JSON will be used instead.");
							$this->dataProvider = new ConfigDataProvider($this, $cacheSize);
						}
					break;
					case "sqlite3":
					case "sqlite":
						if(extension_loaded("sqlite3")) {
							$this->dataProvider = new SQLiteDataProvider($this, $cacheSize);
						}else {
							$this->getLogger()->warning("SQLite3 is not installed in your php build! JSON will be used instead.");
							$this->dataProvider = new ConfigDataProvider($this, $cacheSize);
						}
					break;
					case "json":
					default:
						$this->dataProvider = new ConfigDataProvider($this, $cacheSize);
					break;
				}
			}catch(\Exception $e) {
				$this->getLogger()->error("The selected data provider crashed. JSON will be used instead.");
				$this->dataProvider = new ConfigDataProvider($this, $cacheSize);
			}
		$this->getLogger()->debug(TF::BOLD . "Loading Plot Clearing settings");
		if($this->getConfig()->get("FastClearing", false) === true and $this->getServer()->getPluginManager()->getPlugin("WorldStyler") === null) {
			$this->getConfig()->set("FastClearing", false);
			$this->getLogger()->info(TF::BOLD . "WorldStyler not found. Legacy clearing will be used.");
		}
		$this->getLogger()->debug(TF::BOLD . "Loading economy settings");
		// Initialize EconomyProvider
		if($this->getConfig()->get("UseEconomy", false) === true) {
			if(($plugin = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI")) !== null) {
				if($plugin instanceof EconomyAPI) {
					$this->economyProvider = new EconomySProvider($plugin);
					$this->getLogger()->debug("Eco set to EconomySProvider");
				}else
					$this->getLogger()->debug("Eco not instance of EconomyAPI");
			}
			if(!isset($this->economyProvider)) {
				$this->getLogger()->info("No supported economy plugin found!");
				$this->getConfig()->set("UseEconomy", false);
				//$this->getConfig()->save();
			}
		}
		$this->getLogger()->debug(TF::BOLD . "Loading MyPlot Commands");
		// Register command
		$this->getServer()->getCommandMap()->register("myplot", new Commands($this));
	}

	public function onEnable() : void {
		$this->getLogger()->debug(TF::BOLD . "Loading Events");
		$eventListener = new EventListener($this);
		$this->getServer()->getPluginManager()->registerEvents($eventListener, $this);
		$this->getLogger()->debug(TF::BOLD . "Registering Loaded Levels");
		foreach($this->getServer()->getWorldManager()->getWorlds() as $level) {
			$eventListener->onLevelLoad(new WorldLoadEvent($level));
		}
		$this->getLogger()->debug(TF::BOLD.TF::GREEN."Enabled!");
	}

	public function addLevelSettings(string $levelName, PlotLevelSettings $settings) : bool {
		$this->levels[$levelName] = $settings;
		return true;
	}

	public function unloadLevelSettings(string $levelName) : bool {
		if(isset($this->levels[$levelName])) {
			unset($this->levels[$levelName]);
			$this->getLogger()->debug("Level " . $levelName . " settings unloaded!");
			return true;
		}
		return false;
	}

	public function onDisable() : void {
		$this->dataProvider->close();
	}
}
