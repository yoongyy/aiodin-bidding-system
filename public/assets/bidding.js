export function normalizeAuctionState(state = {}) {
  const countdown = state.countdown ?? {};
  const bids = Array.isArray(state.bids) ? state.bids : [];

  return {
    slug: state.slug ?? '',
    title: state.title ?? 'Auction Product',
    description: state.description ?? '',
    imageUrl: state.imageUrl ?? '',
    currency: state.currency ?? 'MYR',
    startingPrice: Number(state.startingPrice ?? 0),
    currentBid: state.currentBid === null || state.currentBid === undefined ? null : Number(state.currentBid),
    displayPrice: Number(state.displayPrice ?? state.currentBid ?? state.startingPrice ?? 0),
    status: state.status ?? 'scheduled',
    statusLabel: state.statusLabel ?? 'Waiting for first bid',
    statusTone: state.statusTone ?? 'idle',
    countdown: {
      secondsRemaining: Number(countdown.secondsRemaining ?? 0),
      endsAt: countdown.endsAt ?? null,
      startedAt: countdown.startedAt ?? null,
    },
    winner: state.winner ?? null,
    bidCount: Number(state.bidCount ?? bids.length),
    bids,
    startedAt: state.startedAt ?? null,
    endedAt: state.endedAt ?? null,
    lastUpdatedAt: state.lastUpdatedAt ?? null,
  };
}

export function formatCurrency(amount, currency = 'MYR') {
  const value = Number(amount ?? 0);

  try {
    return new Intl.NumberFormat('en-MY', {
      style: 'currency',
      currency,
      maximumFractionDigits: 2,
      minimumFractionDigits: 2,
    }).format(value);
  } catch {
    return `${currency} ${value.toFixed(2)}`;
  }
}

export function formatCountdown(secondsRemaining) {
  const seconds = Math.max(0, Math.floor(Number(secondsRemaining ?? 0)));
  const minutesPart = String(Math.floor(seconds / 60)).padStart(2, '0');
  const secondsPart = String(seconds % 60).padStart(2, '0');

  return `${minutesPart}:${secondsPart}`;
}

export function auctionTone(state) {
  const status = state?.status ?? 'scheduled';

  switch (status) {
    case 'live':
      return {
        label: 'In progress',
        message: 'Bidding is live.',
        tone: 'live',
        canBid: true,
      };
    case 'ended':
      return {
        label: 'Ended',
        message: 'The auction is complete.',
        tone: 'ended',
        canBid: false,
      };
    default:
      return {
        label: 'Waiting for first bid',
        message: 'The countdown will start with the first valid bid.',
        tone: 'idle',
        canBid: true,
      };
  }
}

export function nextMinimumBid(state) {
  if ((state?.status ?? 'scheduled') !== 'live') {
    return Number(Number(state?.startingPrice ?? 0).toFixed(2));
  }

  const currentBid = Number(state?.currentBid ?? state?.displayPrice ?? state?.startingPrice ?? 0);
  return Number((currentBid + 0.01).toFixed(2));
}

export function formatBidLine(bid, currency = 'MYR') {
  return `${bid.bidderName} bid ${formatCurrency(bid.amount, currency)} at ${new Date(bid.createdAt).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
  })}`;
}
