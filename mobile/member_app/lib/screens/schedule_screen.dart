import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';

/// The timetable the member books from. Classes and pool sessions share the
/// same list, because to the member they are the same decision.
class ScheduleScreen extends StatefulWidget {
  const ScheduleScreen({super.key});

  @override
  State<ScheduleScreen> createState() => _ScheduleScreenState();
}

class _ScheduleScreenState extends State<ScheduleScreen> {
  List<ClassSession> _sessions = const [];
  bool _loading = true;
  int? _busySessionId;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = SessionScope.of(context).api;
    final locale = TranslationsScope.of(context).locale;

    setState(() => _loading = true);

    try {
      final response = await api.get('/me/schedule');

      setState(() {
        _sessions = (response as List)
            .cast<Map>()
            .map((row) => ClassSession.fromJson(row.cast<String, dynamic>(), locale: locale))
            .toList();
      });
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _book(ClassSession session) async {
    setState(() => _busySessionId = session.id);

    try {
      await SessionScope.of(context).api.post('/me/sessions/${session.id}/book');
      await _load();
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _busySessionId = null);
    }
  }

  /// Grouped by day, which is how a timetable is read.
  Map<String, List<ClassSession>> get _byDay {
    final groups = <String, List<ClassSession>>{};

    for (final session in _sessions) {
      final key = DateFormat('yyyy-MM-dd').format(session.startsAt);
      groups.putIfAbsent(key, () => []).add(session);
    }

    return groups;
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    if (_loading && _sessions.isEmpty) {
      return const Center(child: CircularProgressIndicator(color: AppTheme.brand));
    }

    final days = _byDay.entries.toList()..sort((a, b) => a.key.compareTo(b.key));

    return RefreshIndicator(
      onRefresh: _load,
      color: AppTheme.brand,
      backgroundColor: AppTheme.ink800,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 110),
        children: [
          Text(
            t.t('classes.timetable', 'Timetable'),
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 16),

          if (days.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 60),
              child: Center(
                child: Text(
                  t.t('classes.no_sessions', 'No sessions scheduled.'),
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            ),

          for (final day in days) ...[
            Padding(
              padding: const EdgeInsets.only(bottom: 8, top: 6),
              child: Text(
                DateFormat.MMMEd().format(DateTime.parse(day.key)),
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ),
            for (final session in day.value)
              _SessionCard(
                session: session,
                busy: _busySessionId == session.id,
                onBook: () => _book(session),
              ),
          ],
        ],
      ),
    );
  }
}

class _SessionCard extends StatelessWidget {
  const _SessionCard({required this.session, required this.busy, required this.onBook});

  final ClassSession session;
  final bool busy;
  final VoidCallback onBook;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return GlassCard(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      child: Row(
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                DateFormat.Hm().format(session.startsAt),
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w700,
                  fontSize: 16,
                ),
              ),
              Text(
                DateFormat.Hm().format(session.endsAt),
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ],
          ),
          const SizedBox(width: 14),

          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  session.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                ),
                if (session.coachName != null) ...[
                  const SizedBox(height: 2),
                  Text(session.coachName!, style: Theme.of(context).textTheme.labelSmall),
                ],
                const SizedBox(height: 8),
                // The seat bar is the fastest read of whether to hurry.
                Row(
                  children: [
                    Expanded(
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(999),
                        child: LinearProgressIndicator(
                          value: session.occupancy,
                          minHeight: 5,
                          backgroundColor: Colors.white.withValues(alpha: 0.08),
                          color: session.isFull ? AppTheme.danger : AppTheme.brand,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      '${session.remainingSeats}',
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),

          if (session.isBooked)
            const Icon(Icons.check_circle, color: AppTheme.brand)
          else if (busy)
            const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2, color: AppTheme.brand),
            )
          else
            FilledButton(
              onPressed: session.isBookable ? onBook : null,
              style: FilledButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              ),
              child: Text(
                session.isFull
                    ? t.t('booking.session_full', 'Full')
                    : t.t('members.book', 'Book'),
                style: const TextStyle(fontSize: 13),
              ),
            ),
        ],
      ),
    );
  }
}
