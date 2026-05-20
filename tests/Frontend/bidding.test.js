import { describe, expect, it } from 'vitest';
import {
  auctionTone,
  formatBidLine,
  formatCountdown,
  formatCurrency,
  nextMinimumBid,
  normalizeAuctionState,
} from '../../public/assets/bidding.js';

describe('bidding helpers', () => {
  it('formats countdowns', () => {
    expect(formatCountdown(61)).toBe('01:01');
    expect(formatCountdown(5)).toBe('00:05');
    expect(formatCountdown(-5)).toBe('00:00');
  });

  it('formats money with a stable fraction', () => {
    const output = formatCurrency(1250, 'MYR');
    expect(output).toMatch(/1,250\.00$/);
    expect(output).toMatch(/^(MYR|RM)/);
  });

  it('derives auction tone metadata', () => {
    expect(auctionTone({ status: 'scheduled' })).toMatchObject({ label: 'Waiting for first bid', canBid: true });
    expect(auctionTone({ status: 'live' })).toMatchObject({ label: 'In progress', canBid: true });
    expect(auctionTone({ status: 'ended' })).toMatchObject({ label: 'Ended', canBid: false });
  });

  it('normalizes and advances the next minimum bid', () => {
    const state = normalizeAuctionState({ status: 'live', currentBid: 500, startingPrice: 500 });
    expect(nextMinimumBid(state)).toBe(500.01);
  });

  it('uses starting price as next minimum in scheduled state', () => {
    const state = normalizeAuctionState({ status: 'scheduled', startingPrice: 7000 });
    expect(nextMinimumBid(state)).toBe(7000);
  });

  it('normalizes missing state values safely', () => {
    const state = normalizeAuctionState();

    expect(state.slug).toBe('');
    expect(state.title).toBe('Auction Product');
    expect(state.currency).toBe('MYR');
    expect(state.countdown.secondsRemaining).toBe(0);
    expect(state.bids).toEqual([]);
  });

  it('formats a bid feed line', () => {
    const line = formatBidLine({
      bidderName: 'Aina',
      amount: 500,
      createdAt: '2026-05-20T10:00:00+00:00',
    }, 'MYR');

    expect(line).toContain('Aina bid');
    expect(line).toContain('500.00');
  });
});
