<?php

require __DIR__ . '/vendor/autoload.php';
use Web3\Web3;
use Web3\Contract;
use Web3\Utils;
use Web3\Formatters\AddressFormatter;
use GuzzleHttp\Promise\Promise;

$web3 = null;
$eth = null;
$pcsContract = null;
$pairContract = null;
$pairsA = null;
$pairsB = null;
$contractDecimals = null;
$PANCAKESWAP_FACTORY_ADDR_V1 = "0xbcfccbde45ce874adcb698cc183debcf17952812";
$PANCAKESWAP_FACTORY_ADDR_V2 = "0xcA143Ce32Fe78f1f7019d7d551a6402fC5350c73";
$ADDRESS_BNB = "0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c";
$ADDRESS_RISKMOON = "0xa96f3414334F5A0A529ff5d9D8ea95f42147b8C9";
$ADDRESS_USDT = "0xe9e7cea3dedca5984780bafc599bd69add087d56";
$ENABLE_PANCAKESWAP_V1 = true;
$ENABLE_PANCAKESWAP_V2 = true;
$BSC_HOST = "https://bsc-dataseed.binance.org";


function getPrice() {
  global $pairsA;
  global $pairsB;
  global $contractDecimals;

  $pairAreserves0 = 0;
  $pairAreserves1 = 0;
  $pairBreserves0 = 0;
  $pairBreserves1 = 0;
  foreach($pairsA as $pair) {
    $result = getReserves($pair);
    $pairAreserves0 += $result[0];
    $pairAreserves1 += $result[1];
  }
  foreach($pairsB as $pair) {
    $result = getReserves($pair);
    $pairBreserves0 += $result[0];
    $pairBreserves1 += $result[1];
  }
  $pricePairA = $pairAreserves1/$pairAreserves0;
  $pricePairB = $pairBreserves1/$pairBreserves0;
  $price = $pricePairA * $pricePairB;

  return $price / pow(10, $contractDecimals);
}

function getContractPairInfo ($factoryAddress, $address0, $address1) {
  global $pcsContract;
  $result = makeContractCall($pcsContract, $factoryAddress, "getPair", $address0, $address1)->wait();
  $formatter = new AddressFormatter;
  $address = $formatter->format($result);
  return array(
    "address" => $address,
    "address0" => $address0,
    "address1" => $address1
  );
}

function getReserves($contractPairInfo) {
  global $pairContract;

  $token0 = makeContractCall($pairContract, $contractPairInfo["address"], "token0")->wait();

  $formatter = new AddressFormatter();
  $token0address = "0x".substr($token0, -40);
  $isOrderReversed = strtolower($contractPairInfo["address0"]) != strtolower($token0address);

  $reservesResult = makeContractCall($pairContract, $contractPairInfo["address"], "getReserves")->wait();

  $reservesResult = Utils::stripZero($reservesResult);
  $reservesChunks = str_split($reservesResult, 64);

  return array(
    "0" => hexdec($reservesChunks[$isOrderReversed ? 1 : 0]),
    "1" => hexdec($reservesChunks[$isOrderReversed ? 0 : 1])
  );
}

function makeContractCall($contract, $contractAddress, $methodName) {
  global $eth;

  $arguments = func_get_args();
  $params = array_slice($arguments, 3, count($arguments)-3);
  array_unshift($params, $methodName);
  $data = call_user_func_array(array($contract, "getData"), $params);
  $promise = new Promise();
  $eth->call([
    'to' => $contractAddress,
    'data' => '0x' . $data
  ], function ($err, $response) use(&$promise) {
      if ($err !== null) {
          $promise->reject($err);
          return;
      }
      $promise->resolve($response);
  });
  return $promise;
}



try {
  // Connect to Ganache
    $web3 = new Web3($BSC_HOST);
    $eth = $web3->eth;


    $pancakeFactoryAbi = json_decode(file_get_contents("./abis/PancakeFactoryV2.json"));
    $pancakePairAbi = json_decode(file_get_contents("./abis/PancakePair.json"));
    $riskMoonAbi = json_decode(file_get_contents("./abis/RiskMoon.json"));

    $pcsContract = new Contract($BSC_HOST, $pancakeFactoryAbi);
    $pairContract = new Contract($BSC_HOST, $pancakePairAbi);
    $riskmoonContract = new Contract($BSC_HOST, $riskMoonAbi);

    $contractDecimals = hexdec(makeContractCall($riskmoonContract, $ADDRESS_RISKMOON, "decimals")->wait());

    $pairsA =  [
      getContractPairInfo($PANCAKESWAP_FACTORY_ADDR_V1, $ADDRESS_RISKMOON, $ADDRESS_BNB),
      getContractPairInfo($PANCAKESWAP_FACTORY_ADDR_V2, $ADDRESS_RISKMOON, $ADDRESS_BNB)
    ];

    $pairsB =  [
      getContractPairInfo($PANCAKESWAP_FACTORY_ADDR_V1, $ADDRESS_BNB, $ADDRESS_USDT),
      getContractPairInfo($PANCAKESWAP_FACTORY_ADDR_V2, $ADDRESS_BNB, $ADDRESS_USDT)
    ];

    while(true) {
      sleep(0.5);
      $price = getPrice();
      $pricePerM = $price * pow(10,6);
      echo "price per 1M = " . $pricePerM . "\n";
    }

}
catch (\Exception $exception) {
    print($exception);
    die ("Unable to connect");
}
?>
