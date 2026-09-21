/* Pure offline projection, shared by the Coach App and its regression tests. */
((root) => {
    'use strict';
    const copy = (value) => JSON.parse(JSON.stringify(value));
    const dateAt = (instant, zone) => {
        const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit'}).formatToParts(new Date(instant)).map(part => [part.type, part.value]));
        return `${parts.year}-${parts.month}-${parts.day}`;
    };
    const groupId = (op) => op.group_id || (op.group_name ? -Array.from(op.group_name).reduce((hash, c) => (hash * 31 + c.charCodeAt(0)) % 2147483647, 1) : null);
    const project = (snapshot, queue) => {
        const data = copy(snapshot);
        for (const op of queue) {
            // A stale group assignment has been resolved in favour of the server.
            if (op.resolved) continue;
            const roster = data.roster.skaters.find(s => s.public_id === op.skater_id);
            const assess = data.assess.skaters.find(s => s.public_id === op.skater_id);
            const profile = data.profiles[op.skater_id];
            if (!roster || !assess || !profile) continue;
            if (op.kind === 'group') {
                const group = {group_id: groupId(op), group_name: op.display_name || 'No group', group_colour: op.display_colour || '#ffffff'};
                Object.assign(roster, group); Object.assign(assess, group);
                for (const enrollment of profile.enrollments || []) {
                    if (Number(enrollment.program_session_id ?? enrollment.session_id) === op.session_id) Object.assign(enrollment, group);
                }
            } else if (op.kind === 'attendance') {
                if (op.date === data.roster.attendance.date) Object.assign(roster, {
                    present: op.present,
                    attendance_recorded: true,
                    attendance_status: op.present ? 'PRESENT' : 'ABSENT',
                    attendance_pending_sync: !op.acked,
                });
            } else {
                const date = dateAt(op.occurred_at, data.time_zone);
                const pendingSync = !op.acked;
                assess[op.kind] ||= {};
                assess[op.kind][op.target_id] = op.kind === 'skills' ? date : op.occurred_at;
                if (op.kind === 'skills' && pendingSync) {
                    assess.pending_sync ||= {};
                    assess.pending_sync.skills ||= {};
                    assess.pending_sync.skills[op.target_id] = true;
                }
                for (const stage of profile.achievement_editor || []) {
                    if (op.kind === 'badges' && Number(stage.id) === op.target_id) { stage.badge_awarded_at = op.occurred_at; stage.badge_removable_today = !op.attempted; stage.pending_sync = pendingSync; }
                    for (const ribbon of stage.ribbons || []) {
                        if (op.kind === 'ribbons' && Number(ribbon.id) === op.target_id) { ribbon.awarded_at = op.occurred_at; ribbon.removable_today = !op.attempted; ribbon.pending_sync = pendingSync; }
                        for (const skill of ribbon.skills || []) {
                            if (op.kind === 'skills' && Number(skill.id) === op.target_id) {
                                Object.assign(skill, {achieved: true, achievement_date: date, removable_today: !op.attempted, pending_sync: pendingSync});
                                if (Number(stage.number) === 0 && !skill.is_participation) {
                                    for (const r of stage.ribbons || []) for (const participation of r.skills || []) {
                                        if (participation.is_participation && !participation.achieved) {
                                            Object.assign(participation, {achieved: true, achievement_date: date, removable_today: false, pending_sync: pendingSync});
                                            assess.skills[participation.id] = date;
                                            if (pendingSync) assess.pending_sync.skills[participation.id] = true;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return data;
    };
    const achievement = (data, skaterId, kind, id) => {
        for (const stage of data.profiles[skaterId]?.achievement_editor || []) {
            if (kind === 'badges' && Number(stage.id) === id) return {active: !!stage.badge_awarded_at, eligible: stage.has_badge && stage.ribbons.filter(r => r.awarded_at).length >= Number(stage.required_ribbon_count || stage.ribbons.length)};
            for (const ribbon of stage.ribbons || []) {
                if (kind === 'ribbons' && Number(ribbon.id) === id) {
                    const skills = ribbon.skills.filter(s => ribbon.is_participation_ribbon ? s.is_participation : !s.is_participation);
                    const required = Math.min(skills.length, Number(ribbon.required_count || skills.length));
                    return {active: !!ribbon.awarded_at, eligible: required > 0 && skills.filter(s => s.achieved).length >= required};
                }
                for (const skill of ribbon.skills || []) if (kind === 'skills' && Number(skill.id) === id) return {active: !!skill.achieved, eligible: true};
            }
        }
        return null;
    };
    const enqueue = (state, op, undo = false) => {
        const keyMatch = item => item.kind === op.kind && item.skater_id === op.skater_id && item.target_id === op.target_id && item.date === op.date;
        const previous = state.queue.find(keyMatch);
        if (previous?.attempted) throw new Error('This change is awaiting server confirmation. Reconnect before changing it again.');
        const data = project(state.snapshot, state.queue);
        if (['skills', 'ribbons', 'badges'].includes(op.kind)) {
            const target = achievement(data, op.skater_id, op.kind, op.target_id);
            if (!target) throw new Error('This achievement is not in the downloaded curriculum.');
            if (undo) {
                if (!previous || previous.acked) throw new Error('Only newly queued achievements can be undone offline.');
                const remaining = state.queue.filter(item => item !== previous);
                const without = project(state.snapshot, remaining);
                for (const item of remaining.filter(item => item.skater_id === op.skater_id && ['ribbons', 'badges'].includes(item.kind))) {
                    if (!achievement(without, item.skater_id, item.kind, item.target_id)?.eligible) throw new Error('Undo the queued badge or ribbon that depends on this achievement first.');
                }
                state.queue = remaining;
                return;
            }
            if (target.active) return;
            if (!target.eligible) throw new Error('Complete the required skills or ribbons before recording this award.');
        }
        if (previous) {
            op.expected = previous.expected;
            state.queue[state.queue.indexOf(previous)] = op;
        } else state.queue.push(op);
    };
    root.CATRinkOfflineModel = {copy, dateAt, groupId, project, enqueue, achievement};
})(typeof window === 'undefined' ? globalThis : window);
