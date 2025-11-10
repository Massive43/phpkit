const fs = require('fs');
const axios = require('axios');
const ethers = require('ethers');
const bip39 = require('bip39');
const { BIP32Factory } = require('bip32');
const ecc = require('tiny-secp256k1');

const bip32 = BIP32Factory(ecc);

// ===== CONFIG =====
const config = {
  infuraProjectId: 'b6bf7d3508c941499b10025c0776eaf8',
  outputFile: 'multichain_wallets.txt',
  minBalance: 0.00001,
  scanDelay: 1000,
  maxScans: 1000000,
  derivationPath: "m/44'/60'/0'/0/0",
  timeout: 10000,
};

// ===== SUPPORTED BLOCKCHAINS =====
const chains = [
  {
    name: 'Ethereum',
    rpc: `https://mainnet.infura.io/v3/${config.infuraProjectId}`,
    symbol: 'ETH',
    tokens: {
      USDT: '0xdAC17F958D2ee523a2206206994597C13D831ec7',
      USDC: '0xA0b86991C6218b36c1d19D4a2e9Eb0cE3606eB48',
      SHIB: '0x95aD61b0a150d79219dCF64E1E6Cc01f0B64C4cE',
      PEPE: '0x6982508145454Ce325dDbE47a25d4ec3d2311933',
      WBTC: '0x2260FAC5E5542a773Aa44fBCfeDf7C193bc2C599'
    }
  },
  {
    name: 'BNB Smart Chain',
    rpc: 'https://bsc-dataseed1.binance.org/',
    symbol: 'BNB',
    tokens: {
      USDT: '0x55d398326f99059fF775485246999027B3197955',
      BUSD: '0xe9e7cea3dedca5984780bafc599bd69add087d56',
      CAKE: '0x0e09fabb73bd3ade0a17ecc321fd13a19e81ce82',
      ADA: '0x3ee2200efb3400fabb9aacf31297cbdd1d435d47'
    }
  },
  {
    name: 'Polygon',
    rpc: 'https://polygon-rpc.com',
    symbol: 'MATIC',
    tokens: {
      USDT: '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',
      USDC: '0x2791Bca1f2de4661ED88A30C99A7a9449Aa84174',
      QUICK: '0x831753dd7087cac61ab5644b308642cc1c33dc13',
      WETH: '0x7ceB23fD6bC0adD59E62ac25578270cFf1b9f619'
    }
  },
  {
    name: 'Arbitrum One',
    rpc: 'https://arb1.arbitrum.io/rpc',
    symbol: 'ETH',
    tokens: {
      USDT: '0xfd086bc7cd5c481dcc9c85ebe478a1c0b69fcbb9',
      USDC: '0xaf88d065e77c8cc2239327c5edb3a432268e5831',
      ARB: '0x912CE59144191C1204E64559FE8253a0e49E6548',
      GMX: '0xfc5A1A6EB076a2C7aD06eD22C90d7E710E35ad0a'
    }
  },
  {
    name: 'Optimism',
    rpc: 'https://mainnet.optimism.io',
    symbol: 'ETH',
    tokens: {
      USDT: '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58',
      USDC: '0x7F5c764cBc14f9669B88837ca1490cCa17c31607',
      OP: '0x4200000000000000000000000000000000000042'
    }
  },
  {
    name: 'Base',
    rpc: 'https://mainnet.base.org',
    symbol: 'ETH',
    tokens: {
      USDC: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
      BALD: '0x27D2DECb4bFC9C76F0309b8E88dec3a601Fe25a8',
      AERO: '0x940181a94A35A4569E4529A3CDfB74e38FD98631'
    }
  },
  {
    name: 'Avalanche C-Chain',
    rpc: 'https://api.avax.network/ext/bc/C/rpc',
    symbol: 'AVAX',
    tokens: {
      USDT: '0xc7198437980c041c805a1edcba50c1ce5db95118',
      USDC: '0xb97ef9ef8734c71904d8002f8b6bc66dd9c48a6e',
      JOE: '0x6e84a6216eA6dACC71eE8E6b0a5B7322EEbC0fDd'
    }
  },
  {
    name: 'Fantom Opera',
    rpc: 'https://rpcapi.fantom.network',
    symbol: 'FTM',
    tokens: {
      USDT: '0x049d68029688eabf473097a2fc38ef61633a3c7a',
      BOO: '0x841fad6eae12c286d1fd18d1d525dffa75c7effe',
      SPELL: '0x468003B688943977e6130F4F68F23aad939a1040'
    }
  },
  {
    name: 'Cronos',
    rpc: 'https://evm.cronos.org',
    symbol: 'CRO',
    tokens: {
      USDT: '0x66e428c3f67a68878562e79A0234c1F83c208770',
      USDC: '0xc21223249CA28397B4B6541dfFaEcC539BfF0c59',
      VVS: '0x2D03bECE6747ADC00E1a131BBA1469C15fD11e03'
    }
  },
  {
    name: 'Gnosis Chain',
    rpc: 'https://rpc.gnosischain.com',
    symbol: 'xDAI',
    tokens: {
      USDT: '0x4ECaBa5870353805a9F068101A40E0f32ed605C6',
      USDC: '0xDDAfbb505ad214D7b80b1f830fcCc89B60fb7A83',
      GNO: '0x9C58BAcC331c9aa871AFD802DB6379a98e80CEdb'
    }
  },
  {
    name: 'Moonbeam',
    rpc: 'https://rpc.api.moonbeam.network',
    symbol: 'GLMR',
    tokens: {
      USDT: '0x8e70cd5b4ff3f62659049e74b6649c6603a0e594',
      USDC: '0x818ec0A7Fe18Ff94269904fCED6AE3DaE6d6dC0b'
    }
  },
  {
    name: 'Moonriver',
    rpc: 'https://rpc.api.moonriver.moonbeam.network',
    symbol: 'MOVR',
    tokens: {
      USDT: '0xB44a9B6905aF7c801311e8F4E76932ee959c663C',
      USDC: '0xE3F5a90F9cb311505cd691a46596599aA1A0AD7D'
    }
  },
  {
    name: 'Harmony One',
    rpc: 'https://api.harmony.one',
    symbol: 'ONE',
    tokens: {
      USDT: '0x3c2b8be99c50593081eaa2a724f0b8285f5aba8f',
      USDC: '0x985458e523db3d53125813ed68c274899e9dfab4',
      '1ETH': '0x6983D1E6DEf3690C4d616b13597A09e6193EA013'
    }
  },
  {
    name: 'Celo',
    rpc: 'https://forno.celo.org',
    symbol: 'CELO',
    tokens: {
      cUSD: '0x765DE816845861e75A25fCA122bb6898B8B1282a',
      cEUR: '0xD8763CBa276a3738E6DE85b4b3bF5FDed6D6cA73',
      USDC: '0xef4229c8c3250C675F21BCefa42f58EfbfF6002a'
    }
  },
  {
    name: 'Fuse',
    rpc: 'https://rpc.fuse.io',
    symbol: 'FUSE',
    tokens: {
      USDT: '0xFaDbBF8Ce7D5b7041bE672561bbA99f79c532e10',
      USDC: '0x620fd5fa44BE6af63715Ef4E65FDF3960d6A4e7a'
    }
  },
  {
    name: 'Aurora',
    rpc: 'https://mainnet.aurora.dev',
    symbol: 'ETH',
    tokens: {
      USDT: '0x4988a896b1227218e4A686fdE5EabdcAbd91571f',
      USDC: '0xB12BFcA5A55806AaF64E99521918A4bf0fC40802'
    }
  },
  {
    name: 'Metis',
    rpc: 'https://andromeda.metis.io/?owner=1088',
    symbol: 'METIS',
    tokens: {
      USDT: '0xbB06DCA3AE6887fAbF931640f67cab3e3a16F4dC',
      USDC: '0xEA32A96608495e54156Ae48931A7c20f0dcc1a21'
    }
  },
  {
    name: 'Kava',
    rpc: 'https://evm.kava.io',
    symbol: 'KAVA',
    tokens: {
      USDT: '0x919C1c267BC06a7039e03fcc2eF738525769109c',
      USDC: '0xfA9343C3897324496A05fC75abeD6bAC29f8A40f'
    }
  },
  {
    name: 'zkSync Era',
    rpc: 'https://mainnet.era.zksync.io',
    symbol: 'ETH',
    tokens: {
      USDC: '0x3355df6D4c9C3035724Fd0e3914dE96A5a83aaf4',
      USDT: '0x493257fD37EDB34451f62EDf8D2a0C418852bA4C'
    }
  },
  {
    name: 'Linea',
    rpc: 'https://rpc.linea.build',
    symbol: 'ETH',
    tokens: {
      USDC: '0x176211869cA2b568f2A7D4EE941E073a821EE1ff',
      USDT: '0xA219439258ca9da29E9Cc4cE5596924745e12B93'
    }
  },
  {
    name: 'Scroll',
    rpc: 'https://rpc.scroll.io',
    symbol: 'ETH',
    tokens: {
      USDC: '0x06eFdBFf2a14a7c8E15944D1F4A48F9F95F663A4',
      USDT: '0xf55BEC9cafDbE8730f096Aa55dad6D22d44099Df'
    }
  },
  {
    name: 'Mantle',
    rpc: 'https://rpc.mantle.xyz',
    symbol: 'MNT',
    tokens: {
      USDT: '0x201EBa5CC46C216A6C6a3b6eE64a1e3c5b1b6C2a',
      USDC: '0x09Bc4E0D864854c6aFB6eB9A9cdF58aC190D0dF9'
    }
  }
];

// === Generate random mnemonic ===
function generateRandomMnemonic() {
  return bip39.generateMnemonic();
}

// === Derive private key (safe hex) ===
async function mnemonicToPrivateKeyHex(mnemonic) {
  try {
    const seed = await bip39.mnemonicToSeed(mnemonic);
    const root = bip32.fromSeed(seed);
    const child = root.derivePath(config.derivationPath);
    if (!child.privateKey) throw new Error('No private key derived');
    return '0x' + Buffer.from(child.privateKey).toString('hex');
  } catch (err) {
    console.error('❌ Error deriving private key:', err.message);
    return null;
  }
}

// === Safe RPC Call with retry logic ===
async function safeRpcCall(rpcUrl, payload, retries = 3) {
  for (let i = 0; i < retries; i++) {
    try {
      const response = await axios.post(rpcUrl, payload, { 
        timeout: config.timeout 
      });
      return response.data;
    } catch (error) {
      if (i === retries - 1) throw error;
      await delay(1000 * (i + 1));
    }
  }
}

// === Delay function ===
function delay(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

// === Get native coin balance ===
async function getBalance(rpcUrl, address) {
  const payload = {
    jsonrpc: '2.0',
    method: 'eth_getBalance',
    params: [address, 'latest'],
    id: 1
  };
  try {
    const data = await safeRpcCall(rpcUrl, payload);
    const result = data.result;
    if (!result || result === '0x') return 0;
    return parseInt(result, 16) / 1e18;
  } catch (err) {
    console.error(`⚠️ RPC Error (${rpcUrl}): ${err.message}`);
    return 0;
  }
}

// === Get ERC20 token balance ===
async function getTokenBalance(rpcUrl, tokenAddress, userAddress) {
  const data = '0x70a08231000000000000000000000000' + userAddress.slice(2);
  const payload = {
    jsonrpc: '2.0',
    method: 'eth_call',
    params: [{ to: tokenAddress, data: data }, 'latest'],
    id: 1
  };
  try {
    const data = await safeRpcCall(rpcUrl, payload);
    const result = data.result;
    if (!result || result === '0x') return 0;
    const balance = parseInt(result, 16) / 1e18;
    return balance;
  } catch (err) {
    return 0;
  }
}

// === Check one wallet across all chains ===
async function checkWallet() {
  const mnemonic = generateRandomMnemonic();
  const privateKeyHex = await mnemonicToPrivateKeyHex(mnemonic);
  if (!privateKeyHex) {
    console.log('⏩ Skipping wallet (invalid key)');
    return;
  }

  let wallet;
  try {
    wallet = new ethers.Wallet(privateKeyHex);
  } catch (err) {
    console.error('❌ Wallet creation failed:', err.message);
    return;
  }

  const address = wallet.address;
  console.log(`\n🧠 Mnemonic: ${mnemonic}`);
  console.log(`🔑 Private Key: ${privateKeyHex}`);
  console.log(`📬 Address: ${address}`);

  let found = false;
  let totalChainsChecked = 0;

  for (const chain of chains) {
    totalChainsChecked++;
    process.stdout.write(`🔍 Checking ${chain.name}... `);
    
    const balance = await getBalance(chain.rpc, address);
    console.log(`${balance.toFixed(8)} ${chain.symbol}`);

    // Check ERC20 tokens
    for (const [tokenName, tokenAddress] of Object.entries(chain.tokens)) {
      const tokenBal = await getTokenBalance(chain.rpc, tokenAddress, address);
      if (tokenBal > 0) {
        console.log(`   🪙 ${tokenName}: ${tokenBal}`);
        found = true;
        const info =
`=============================
Chain: ${chain.name}
Mnemonic: ${mnemonic}
Private Key: ${privateKeyHex}
Address: ${address}
Token: ${tokenName}
Token Balance: ${tokenBal}
=============================\n`;
        fs.appendFileSync(outputFile, info, 'utf8');
      }
    }

    if (balance >= config.minBalance) {
      found = true;
      const info =
`=============================
Chain: ${chain.name}
Mnemonic: ${mnemonic}
Private Key: ${privateKeyHex}
Address: ${address}
Balance: ${balance} ${chain.symbol}
=============================\n`;
      fs.appendFileSync(outputFile, info, 'utf8');
      console.log(`   ✅ Saved ${chain.symbol} wallet with balance to ${outputFile}`);
    }

    // Small delay between chain checks to avoid rate limiting
    await delay(100);
  }

  console.log(`📊 Checked ${totalChainsChecked} chains`);
  if (!found) console.log('🚫 No native or token balance found.');
}

// === Statistics ===
let stats = {
  totalScans: 0,
  walletsFound: 0,
  startTime: Date.now()
};

function displayStats() {
  const elapsed = Math.floor((Date.now() - stats.startTime) / 1000);
  const scansPerHour = Math.floor((stats.totalScans / elapsed) * 3600);
  console.log(`\n📈 STATS: Scans: ${stats.totalScans} | Found: ${stats.walletsFound} | Time: ${elapsed}s | Speed: ~${scansPerHour}/hour`);
}

// === Continuous Scan Loop ===
async function start() {
  console.log('🚀 Starting Multi-Chain Wallet Scanner');
  console.log(`📁 Output file: ${config.outputFile}`);
  console.log(`⛓️  Supported chains: ${chains.length}`);
  console.log(`💰 Minimum balance: ${config.minBalance}`);
  console.log('========================================\n');

  // Create output file if it doesn't exist
  if (!fs.existsSync(config.outputFile)) {
    fs.writeFileSync(config.outputFile, 'Multi-Chain Wallet Scanner Results\n\n', 'utf8');
  }

  while (stats.totalScans < config.maxScans) {
    stats.totalScans++;
    console.log(`\n==========================`);
    console.log(`🔍 Scan #${stats.totalScans}`);
    console.log(`==========================`);
    
    await checkWallet();
    displayStats();
    
    // Delay between full wallet scans
    await delay(config.scanDelay);
  }

  console.log('\n✅ Maximum scan limit reached. Scanner stopped.');
}

// Handle graceful shutdown
process.on('SIGINT', () => {
  console.log('\n\n🛑 Scanner stopped by user');
  displayStats();
  process.exit(0);
});

start();