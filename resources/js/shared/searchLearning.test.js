import { describe, expect, it, vi } from 'vitest';
import { createSearchLearner } from './searchLearning';

const mackerel = { id: 8, name: 'MACKEREL FISH (LARGE)' };

const setup = () => {
    let clock = 1_000;
    const send = vi.fn();
    const learner = createSearchLearner(send, { now: () => clock });
    return { send, learner, tick: (ms) => { clock += ms; } };
};

describe('learning shop words from searches', () => {
    it('teaches a word that found nothing to the product picked next', () => {
        const { send, learner, tick } = setup();

        learner.searched('tit', 0);
        learner.searched('titus', 0);
        tick(5_000);
        learner.searched('mackerel', 1);
        learner.picked(mackerel, 'mackerel', 0);

        expect(send).toHaveBeenCalledTimes(1);
        expect(send).toHaveBeenCalledWith(8, 'titus');
    });

    it('keeps the whole word when the cashier backspaces over it', () => {
        const { send, learner } = setup();

        learner.searched('titus', 0);
        learner.searched('titu', 0);
        learner.searched('tit', 0);
        learner.picked(mackerel, 'mack', 0);

        expect(send).toHaveBeenCalledWith(8, 'titus');
    });

    it('forgets a failed word after half a minute', () => {
        const { send, learner, tick } = setup();

        learner.searched('titus', 0);
        tick(31_000);
        learner.picked(mackerel, 'mackerel', 0);

        expect(send).not.toHaveBeenCalled();
    });

    it('teaches the words typed when the pick was not the top result', () => {
        const { send, learner } = setup();

        learner.searched('fish', 4);
        learner.picked(mackerel, 'fish', 2);

        expect(send).toHaveBeenCalledWith(8, 'fish');
    });

    it('teaches nothing when the top result was the one wanted', () => {
        const { send, learner } = setup();

        learner.searched('mackerel', 1);
        learner.picked(mackerel, 'mackerel', 0);

        expect(send).not.toHaveBeenCalled();
    });

    it('never learns a code or a single letter', () => {
        const { send, learner } = setup();

        learner.searched('600123', 0);
        learner.picked(mackerel, 'm', 3);
        learner.picked(mackerel, '6001', 3);

        expect(send).not.toHaveBeenCalled();
    });

    it('a scan clears what was being looked for', () => {
        const { send, learner } = setup();

        learner.searched('titus', 0);
        learner.reset();
        learner.picked(mackerel, 'mackerel', 0);

        expect(send).not.toHaveBeenCalled();
    });
});
