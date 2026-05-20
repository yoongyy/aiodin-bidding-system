import {
  formatCountdown,
  formatCurrency,
  normalizeAuctionState,
} from './bidding.js';

const BID_INCREMENT = 100;

const bootstrapNode = document.getElementById('auction-bootstrap');
const bootstrap = bootstrapNode ? JSON.parse(bootstrapNode.textContent || '{}') : {};
const initialState = normalizeAuctionState(bootstrap.state || {});
const initialProducts = Array.isArray(bootstrap.products) ? bootstrap.products : [];

const state = {
  current: initialState,
  products: initialProducts,
  activeProductSlug: initialState.slug,
  pollTimer: null,
  tickTimer: null,
  refreshing: false,
};

const nodes = {
  countdown: document.getElementById('countdown'),
  submit: document.getElementById('bid-submit'),
  plusButton: document.getElementById('bid-plus-button'),
  bidAmount: document.getElementById('bid-amount'),
  bidderName: document.getElementById('bidder-name'),
  formStatus: document.getElementById('form-status'),
  lastBidAmount: document.getElementById('last-bid-amount'),
  lastBidderName: document.getElementById('last-bidder-name'),
  winnerCard: document.getElementById('winner-card'),
  winnerName: document.getElementById('winner-name'),
  winnerAmount: document.getElementById('winner-amount'),
  productName: document.getElementById('product-name'),
  productDescription: document.getElementById('product-description'),
  productImage: document.getElementById('product-image'),
  productList: document.getElementById('product-list'),
  resetLink: document.getElementById('reset-link'),
};

function buildStateUrl(productSlug) {
  return `/api/auction/state?${new URLSearchParams({ productSlug })}`;
}

function setProductSlugInUrl(productSlug) {
  const url = new URL(window.location.href);
  url.searchParams.set('product', productSlug);
  window.history.replaceState({}, '', url.toString());
}

function renderProducts() {
  if (!nodes.productList) {
    return;
  }

  nodes.productList.textContent = '';
  for (const product of state.products) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'product-chip';
    button.dataset.slug = String(product.slug || '');
    button.setAttribute('aria-pressed', String(product.slug === state.activeProductSlug));
    if (product.slug === state.activeProductSlug) {
      button.classList.add('is-active');
    }

    const image = document.createElement('img');
    image.className = 'product-chip__image';
    image.src = String(product.imageUrl || '');
    image.alt = String(product.title || 'Product image');

    const content = document.createElement('div');
    content.className = 'product-chip__content';

    const title = document.createElement('strong');
    title.className = 'product-chip__title';
    title.textContent = String(product.title || 'Product');

    const price = document.createElement('span');
    price.className = 'product-chip__price';
    price.textContent = formatCurrency(product.displayPrice ?? product.startingPrice ?? 0, product.currency || 'MYR');

    content.append(title, price);
    button.append(image, content);
    button.addEventListener('click', () => switchProduct(String(product.slug || '')));
    nodes.productList.appendChild(button);
  }
}

function renderAuction(auction) {
  if (!auction) {
    return;
  }

  state.current = normalizeAuctionState(auction);
  state.activeProductSlug = state.current.slug;

  if (nodes.countdown) {
    nodes.countdown.textContent = state.current.status === 'live'
      ? formatCountdown(state.current.countdown.secondsRemaining)
      : state.current.status === 'ended'
        ? 'Auction Ended'
        : 'Bid To Start';
  }

  if (nodes.productName) {
    nodes.productName.textContent = state.current.title;
  }

  if (nodes.productDescription) {
    nodes.productDescription.textContent = state.current.description;
  }

  if (nodes.productImage) {
    nodes.productImage.src = state.current.imageUrl || '';
    nodes.productImage.alt = state.current.title;
  }

  if (nodes.resetLink) {
    nodes.resetLink.href = `/?reset=1&product=${encodeURIComponent(state.current.slug)}`;
  }

  if (nodes.lastBidAmount) {
    nodes.lastBidAmount.textContent = formatCurrency(state.current.displayPrice, state.current.currency);
  }

  if (nodes.lastBidderName) {
    const latestBid = state.current.bids[0];
    nodes.lastBidderName.textContent = latestBid?.bidderName ?? 'No bidder yet';
  }

  if (nodes.submit) {
    const canBid = state.current.status !== 'ended';
    nodes.submit.disabled = !canBid;
    nodes.submit.textContent = canBid ? 'Bid' : 'Auction ended';
  }

  if (nodes.plusButton) {
    nodes.plusButton.disabled = state.current.status === 'ended';
  }

  if (nodes.bidAmount) {
    const currentValue = Number(nodes.bidAmount.value);
    if (!Number.isFinite(currentValue) || currentValue <= 0) {
      const base = state.current.currentBid ?? state.current.startingPrice;
      nodes.bidAmount.value = Number(base).toFixed(2);
    }
  }

  if (nodes.winnerCard) {
    const winner = state.current.winner;
    nodes.winnerCard.hidden = state.current.status !== 'ended' || !winner;
    if (winner) {
      if (nodes.winnerName) {
        nodes.winnerName.textContent = winner.bidderName;
      }
      if (nodes.winnerAmount) {
        nodes.winnerAmount.textContent = formatCurrency(winner.amount, state.current.currency);
      }
    }
  }

  renderProducts();
  setProductSlugInUrl(state.current.slug);
}

function tickCountdown() {
  if (!state.current || state.current.status !== 'live' || !state.current.countdown.endsAt) {
    return;
  }

  const remaining = Math.max(0, Math.ceil((new Date(state.current.countdown.endsAt).getTime() - Date.now()) / 1000));
  state.current.countdown.secondsRemaining = remaining;

  if (nodes.countdown) {
    nodes.countdown.textContent = formatCountdown(remaining);
  }

  if (remaining === 0) {
    refreshAuction();
  }
}

async function refreshProducts() {
  const response = await fetch('/api/products', {
    headers: { Accept: 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Unable to fetch products (${response.status})`);
  }

  const data = await response.json();
  state.products = Array.isArray(data.products) ? data.products : [];
  renderProducts();
}

async function refreshAuction() {
  if (state.refreshing) {
    return;
  }

  state.refreshing = true;

  try {
    if (!state.activeProductSlug) {
      await refreshProducts();
      state.activeProductSlug = String(state.products[0]?.slug || '');
      if (!state.activeProductSlug) {
        return;
      }
    }

    const response = await fetch(buildStateUrl(state.activeProductSlug), {
      headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
      throw new Error(`Unable to refresh auction state (${response.status})`);
    }

    const data = await response.json();
    renderAuction(data);
    await refreshProducts();
  } catch (error) {
    if (nodes.formStatus) {
      nodes.formStatus.textContent = error instanceof Error ? error.message : 'Unable to refresh auction state.';
    }
  } finally {
    state.refreshing = false;
  }
}

function getBidderName() {
  const typed = nodes.bidderName?.value?.trim() ?? '';
  if (typed) {
    localStorage.setItem('auction_bidder_name', typed);
    return typed;
  }

  const saved = localStorage.getItem('auction_bidder_name')?.trim() ?? '';
  if (saved) {
    if (nodes.bidderName) {
      nodes.bidderName.value = saved;
    }
    return saved;
  }

  throw new Error('Name is required.');
}

async function submitBid() {
  if (!state.current) {
    return;
  }

  if (state.current.status === 'ended') {
    if (nodes.formStatus) {
      nodes.formStatus.textContent = 'Auction ended. Reset to start again.';
    }
    return;
  }

  try {
    const bidderName = getBidderName();
    const amount = Number(nodes.bidAmount?.value ?? 0);
    if (!Number.isFinite(amount) || amount <= 0) {
      throw new Error('Amount is required.');
    }

    if (nodes.formStatus) {
      nodes.formStatus.textContent = `Submitting ${formatCurrency(amount, state.current.currency)}...`;
    }

    const response = await fetch('/api/auction/bid', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        productSlug: state.activeProductSlug,
        bidderName,
        amount,
      }),
    });

    const data = await response.json();
    if (!response.ok) {
      const message = data?.errors ? Object.values(data.errors).join(' ') : (data?.message ?? 'Bid rejected.');
      throw new Error(message);
    }

    renderAuction(data);
    await refreshProducts();
    if (nodes.formStatus) {
      nodes.formStatus.textContent = `Bid accepted at ${formatCurrency(amount, state.current.currency)}.`;
    }
  } catch (error) {
    if (nodes.formStatus) {
      nodes.formStatus.textContent = error instanceof Error ? error.message : 'Unable to submit bid.';
    }
  }
}

function addOneHundred() {
  const current = Number(nodes.bidAmount?.value ?? 0);
  const base = Number.isFinite(current) && current > 0
    ? current
    : (state.current?.currentBid ?? state.current?.startingPrice ?? 0);

  const next = Number((base + BID_INCREMENT).toFixed(2));
  if (nodes.bidAmount) {
    nodes.bidAmount.value = next.toFixed(2);
  }
}

async function switchProduct(productSlug) {
  if (!productSlug || productSlug === state.activeProductSlug) {
    return;
  }

  state.activeProductSlug = productSlug;
  if (nodes.formStatus) {
    nodes.formStatus.textContent = '';
  }

  await refreshAuction();
}

function startTimers() {
  state.tickTimer = window.setInterval(tickCountdown, 1000);
  state.pollTimer = window.setInterval(refreshAuction, 2000);
}

if (nodes.submit) {
  nodes.submit.addEventListener('click', submitBid);
}

if (nodes.plusButton) {
  nodes.plusButton.addEventListener('click', addOneHundred);
}

const savedBidder = localStorage.getItem('auction_bidder_name')?.trim() ?? '';
if (savedBidder && nodes.bidderName) {
  nodes.bidderName.value = savedBidder;
}

if (state.current) {
  renderAuction(state.current);
}

startTimers();
refreshAuction();
