import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

/// The report catalogue, and whichever one the manager taps. A report comes
/// back as a list, a map or a time series, so the screen renders all three.
class ReportsScreen extends StatefulWidget {
  const ReportsScreen({super.key});

  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen> {
  List<Map<String, dynamic>> _catalogue = const [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final response = await SessionScope.of(context).api.get('/reports');

      setState(() {
        _catalogue =
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

  Map<String, List<Map<String, dynamic>>> get _grouped {
    final groups = <String, List<Map<String, dynamic>>>{};

    for (final report in _catalogue) {
      groups.putIfAbsent('${report['group']}', () => []).add(report);
    }

    return groups;
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return DetailScaffold(
      title: t.t('nav.reports', 'Reports'),
      child: _loading
          ? const LoadingState()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                for (final group in _grouped.entries) ...[
                  Padding(
                    padding: const EdgeInsets.only(top: 6, bottom: 8),
                    child: Text(
                      group.key,
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                  ),
                  for (final report in group.value)
                    GlassCard(
                      margin: const EdgeInsets.only(bottom: 8),
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => ReportDetailScreen(
                            reportKey: '${report['key']}',
                            label: '${report['label']}',
                          ),
                        ),
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${report['label']}',
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                          const Icon(Icons.chevron_right, color: AppTheme.ink400),
                        ],
                      ),
                    ),
                ],
              ],
            ),
    );
  }
}

class ReportDetailScreen extends StatefulWidget {
  const ReportDetailScreen({super.key, required this.reportKey, required this.label});

  final String reportKey;
  final String label;

  @override
  State<ReportDetailScreen> createState() => _ReportDetailScreenState();
}

class _ReportDetailScreenState extends State<ReportDetailScreen> {
  dynamic _data;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final response = await SessionScope.of(context).api.get('/reports/${widget.reportKey}');
      setState(() => _data = (response as Map)['data']);
    } on ApiException catch (error) {
      setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return DetailScaffold(
      title: widget.label,
      child: _loading
          ? const LoadingState()
          : _error != null
              ? EmptyState(icon: Icons.error_outline, message: _error!)
              : RefreshIndicator(
                  onRefresh: _load,
                  color: AppTheme.brand,
                  backgroundColor: AppTheme.ink800,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: _buildBody(t),
                  ),
                ),
    );
  }

  List<Widget> _buildBody(Translations t) {
    final data = _data;

    if (data is List) {
      if (data.isEmpty) {
        return [
          EmptyState(
            icon: Icons.bar_chart_outlined,
            message: t.t('reports.no_data', 'No data for this period.'),
          ),
        ];
      }

      // A time series is a list of {date, total}; anything else is a table.
      final first = data.first;

      if (first is Map && (first.containsKey('date') || first.containsKey('hour'))) {
        return [
          GlassSection(
            title: widget.label,
            child: _SeriesBars(points: SeriesPoint.listFrom(data)),
          ),
        ];
      }

      return [
        for (final row in data.cast<Map>())
          GlassCard(
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final entry in row.entries)
                  if (entry.value is! Map && entry.value is! List)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 2),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${entry.key}',
                              style: Theme.of(context).textTheme.labelSmall,
                            ),
                          ),
                          Text(
                            '${entry.value ?? '—'}',
                            style: const TextStyle(color: Colors.white, fontSize: 13),
                          ),
                        ],
                      ),
                    ),
              ],
            ),
          ),
      ];
    }

    if (data is Map) {
      return [
        for (final entry in data.entries)
          if (entry.value is num || entry.value is String)
            GlassCard(
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      '${entry.key}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ),
                  entry.value is num
                      ? MoneyText(entry.value as num, size: 16)
                      : Text(
                          '${entry.value}',
                          style: const TextStyle(color: Colors.white),
                        ),
                ],
              ),
            ),
      ];
    }

    return [
      EmptyState(
        icon: Icons.bar_chart_outlined,
        message: t.t('reports.no_data', 'No data for this period.'),
      ),
    ];
  }
}

/// A bar per point — enough to read a trend on a phone without a chart
/// library page-load.
class _SeriesBars extends StatelessWidget {
  const _SeriesBars({required this.points});

  final List<SeriesPoint> points;

  @override
  Widget build(BuildContext context) {
    final peak = points.fold<double>(0, (max, point) => point.value > max ? point.value : max);

    return SizedBox(
      height: 150,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          for (final point in points)
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 1),
                child: FractionallySizedBox(
                  heightFactor: peak == 0 ? 0.02 : (point.value / peak).clamp(0.02, 1),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: AppTheme.brand.withValues(alpha: 0.55),
                      borderRadius: const BorderRadius.vertical(top: Radius.circular(3)),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
