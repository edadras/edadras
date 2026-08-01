import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:intl/intl.dart';

/// The club's timetable: which sessions run, how full each is, and who is
/// booked into the one you tap.
class ClassesScreen extends StatefulWidget {
  const ClassesScreen({super.key});

  @override
  State<ClassesScreen> createState() => _ClassesScreenState();
}

class _ClassesScreenState extends State<ClassesScreen> {
  List<ClassSession> _sessions = const [];
  String _kind = '';
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final locale = TranslationsScope.of(context).locale;

    setState(() => _loading = true);

    try {
      final response = await SessionScope.of(context).api.get('/class-sessions', query: {
        'from': DateFormat('yyyy-MM-dd').format(DateTime.now()),
        'to': DateFormat('yyyy-MM-dd')
            .format(DateTime.now().add(const Duration(days: 14))),
      });

      final all = (response as List)
          .cast<Map>()
          .map((row) => ClassSession.fromJson(row.cast<String, dynamic>(), locale: locale))
          .toList();

      setState(() {
        _sessions =
            _kind.isEmpty ? all : all.where((session) => session.kind == _kind).toList();
      });
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _cancel(ClassSession session) async {
    final t = TranslationsScope.of(context);

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        backgroundColor: AppTheme.ink800,
        title: Text(t.t('general.cancel', 'Cancel')),
        content: Text(session.name),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: Text(t.t('general.cancel', 'Back')),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: Text(t.t('general.save', 'Confirm')),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      await SessionScope.of(context).api.post('/class-sessions/${session.id}/cancel');
      await _load();
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  Map<String, List<ClassSession>> get _byDay {
    final groups = <String, List<ClassSession>>{};

    for (final session in _sessions) {
      groups.putIfAbsent(DateFormat('yyyy-MM-dd').format(session.startsAt), () => [])
          .add(session);
    }

    return groups;
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final days = _byDay.entries.toList()..sort((a, b) => a.key.compareTo(b.key));

    return DetailScaffold(
      title: t.t('nav.classes', 'Classes'),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: SegmentedButton<String>(
              segments: [
                ButtonSegment(value: '', label: Text(t.t('classes.all', 'All'))),
                ButtonSegment(value: 'class', label: Text(t.t('classes.class', 'Classes'))),
                ButtonSegment(value: 'pool', label: Text(t.t('classes.pool', 'Pool'))),
              ],
              selected: {_kind},
              onSelectionChanged: (value) {
                setState(() => _kind = value.first);
                _load();
              },
            ),
          ),

          Expanded(
            child: _loading && _sessions.isEmpty
                ? const LoadingState()
                : RefreshIndicator(
                    onRefresh: _load,
                    color: AppTheme.brand,
                    backgroundColor: AppTheme.ink800,
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                      children: [
                        if (days.isEmpty)
                          EmptyState(
                            icon: Icons.event_busy_outlined,
                            message: t.t('classes.no_sessions', 'No sessions scheduled.'),
                          ),
                        for (final day in days) ...[
                          Padding(
                            padding: const EdgeInsets.only(top: 6, bottom: 8),
                            child: Text(
                              DateFormat.MMMEd().format(DateTime.parse(day.key)),
                              style: Theme.of(context).textTheme.labelSmall,
                            ),
                          ),
                          for (final session in day.value)
                            _SessionRow(
                              session: session,
                              onCancel: () => _cancel(session),
                              onOpen: () => Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (_) => SessionBookingsScreen(session: session),
                                ),
                              ),
                            ),
                        ],
                      ],
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}

class _SessionRow extends StatelessWidget {
  const _SessionRow({
    required this.session,
    required this.onCancel,
    required this.onOpen,
  });

  final ClassSession session;
  final VoidCallback onCancel;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final cancelled = session.status != 'scheduled';

    return GlassCard(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      onTap: onOpen,
      child: Row(
        children: [
          Text(
            DateFormat.Hm().format(session.startsAt),
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w700,
              fontSize: 15,
            ),
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
                  style: TextStyle(
                    color: cancelled ? AppTheme.ink400 : Colors.white,
                    fontWeight: FontWeight.w600,
                    decoration: cancelled ? TextDecoration.lineThrough : null,
                  ),
                ),
                const SizedBox(height: 6),
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
                      '${session.bookedCount}/${session.capacity}',
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                  ],
                ),
              ],
            ),
          ),

          if (!cancelled)
            IconButton(
              onPressed: onCancel,
              icon: const Icon(Icons.close, size: 18, color: AppTheme.ink400),
              tooltip: t.t('general.cancel', 'Cancel'),
            ),
        ],
      ),
    );
  }
}

/// Who is booked into one session, and marking who actually turned up.
class SessionBookingsScreen extends StatefulWidget {
  const SessionBookingsScreen({super.key, required this.session});

  final ClassSession session;

  @override
  State<SessionBookingsScreen> createState() => _SessionBookingsScreenState();
}

class _SessionBookingsScreenState extends State<SessionBookingsScreen> {
  List<Map<String, dynamic>> _bookings = const [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    try {
      final response = await SessionScope.of(context)
          .api
          .get('/class-sessions/${widget.session.id}/bookings');

      setState(() {
        _bookings =
            (response as List).cast<Map>().map((row) => row.cast<String, dynamic>()).toList();
      });
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _mark(int bookingId, bool attended) async {
    try {
      await SessionScope.of(context)
          .api
          .post('/bookings/$bookingId/attendance', body: {'attended': attended});
      await _load();
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return DetailScaffold(
      title: widget.session.name,
      child: _loading
          ? const LoadingState()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (_bookings.isEmpty)
                  EmptyState(
                    icon: Icons.people_outline,
                    message: t.t('classes.no_bookings', 'Nobody has booked this session.'),
                  ),
                for (final booking in _bookings)
                  _BookingRow(
                    booking: booking,
                    onAttended: () => _mark(booking['id'] as int, true),
                    onNoShow: () => _mark(booking['id'] as int, false),
                  ),
              ],
            ),
    );
  }
}

class _BookingRow extends StatelessWidget {
  const _BookingRow({
    required this.booking,
    required this.onAttended,
    required this.onNoShow,
  });

  final Map<String, dynamic> booking;
  final VoidCallback onAttended;
  final VoidCallback onNoShow;

  @override
  Widget build(BuildContext context) {
    final member = (booking['member'] as Map?)?.cast<String, dynamic>() ?? const {};
    final status = '${booking['status']}';

    return GlassCard(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      child: Row(
        children: [
          Expanded(
            child: Text(
              '${member['first_name'] ?? ''} ${member['last_name'] ?? ''}'.trim(),
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
            ),
          ),
          IconButton(
            onPressed: onAttended,
            icon: Icon(
              Icons.check_circle,
              color: status == 'attended' ? AppTheme.brand : AppTheme.ink400,
            ),
          ),
          IconButton(
            onPressed: onNoShow,
            icon: Icon(
              Icons.cancel,
              color: status == 'no_show' ? AppTheme.danger : AppTheme.ink400,
            ),
          ),
        ],
      ),
    );
  }
}
