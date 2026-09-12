import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ApprovalCard } from '@/components/chat/approval-card';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { PendingApproval, ToolDescription } from '@/state/types';

/**
 * The gate.
 *
 * The assertion that matters most is the dullest one: the digest that comes
 * out is the digest that went in. nr-llm recomputes it from the run's live
 * state and refuses a mismatch, so anything this component did to it — trimming
 * whitespace, lower-casing, rebuilding it from the calls — would turn every
 * approval into a 409 and leave the conversation waiting for ever.
 */

const DIGEST = '  A1b2C3==/+ trailing  ';

const approval: PendingApproval = {
  runUuid: 'run-1',
  turnDigest: DIGEST,
  calls: [
    { index: 0, callId: 'c1', name: 'typo3_WriteTable', arguments: { table: 'pages', uid: 7 } },
    { index: 1, callId: 'c2', name: 'typo3_CreateSite', arguments: { identifier: 'main' } },
  ],
};

const tools: ToolDescription[] = [
  { name: 'typo3_WriteTable', mcpName: 'WriteTable', effect: 'idempotent_write', requiresApproval: true },
  {
    name: 'typo3_CreateSite',
    mcpName: 'CreateSite',
    effect: 'non_idempotent_write',
    requiresApproval: true,
  },
];

function renderCard(onDecide = vi.fn(), busy = false) {
  render(
    <TooltipProvider>
      <ApprovalCard approval={approval} busy={busy} onDecide={onDecide} tools={tools} />
    </TooltipProvider>,
  );

  return onDecide;
}

describe('ApprovalCard', () => {
  it('names every pending call', () => {
    renderCard();

    expect(screen.getByText('WriteTable')).toBeInTheDocument();
    expect(screen.getByText('CreateSite')).toBeInTheDocument();
  });

  it('says how many decisions are outstanding', () => {
    renderCard();

    expect(screen.getByRole('heading', { level: 3 })).toHaveTextContent('2 tool calls need your approval');
  });

  it('says that nothing has been written yet', () => {
    renderCard();

    expect(screen.getByText(/nothing has been written yet/i)).toBeInTheDocument();
  });

  it('keeps arguments collapsed until asked', () => {
    renderCard();

    expect(screen.queryByText(/"identifier": "main"/)).not.toBeInTheDocument();
  });

  it('shows the arguments of one call on demand', async () => {
    const user = userEvent.setup();
    renderCard();

    await user.click(screen.getAllByRole('button', { name: 'Arguments' })[0] as HTMLElement);

    expect(screen.getByText(/"table": "pages"/)).toBeInTheDocument();
  });

  it('approves with the digest echoed unchanged', async () => {
    const user = userEvent.setup();
    const onDecide = renderCard();

    await user.click(screen.getByRole('button', { name: /approve/i }));

    expect(onDecide).toHaveBeenCalledTimes(1);
    expect(onDecide).toHaveBeenCalledWith(true, false);
    // The component never touches the digest; the caller sends what it was
    // given, and this is the value it was given.
    expect(approval.turnDigest).toBe(DIGEST);
  });

  it('passes the remember flag only when the box is ticked', async () => {
    const user = userEvent.setup();
    const onDecide = renderCard();

    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: /approve/i }));

    expect(onDecide).toHaveBeenCalledWith(true, true);
  });

  it('never remembers a denial', async () => {
    const user = userEvent.setup();
    const onDecide = renderCard();

    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: /deny/i }));

    expect(onDecide).toHaveBeenCalledWith(false, false);
  });

  it('cannot be decided twice while a decision is in flight', () => {
    renderCard(vi.fn(), true);

    expect(screen.getByRole('button', { name: /approve/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: /deny/i })).toBeDisabled();
  });

  it('labels itself for assistive technology', () => {
    renderCard();

    const region = screen.getByRole('region');
    expect(region).toHaveAccessibleName(/need your approval/i);
  });
});
