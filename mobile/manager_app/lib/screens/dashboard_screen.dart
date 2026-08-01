import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

/// The manager's home screen: today's numbers, the month, and the two
/// trends that say whether the club is growing.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  Dashboard? _dashboard;
  String? _error;
  bool _loading = true;

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
      final response = await SessionScope.of(context).api.get('/dashboard');
      setState(() => _dashboard = Dashboard.fromJson((response as Map).cast<String, dynamic>()));
    } on ApiException catch (error) {
      setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final session = SessionScope.of(context);

    if (_loading && _dashboard == null) {
      return const Center(child: CircularProgressIndicator(color: AppTheme.brand));
    }

    if (_error != null && _dashboard == null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_error!, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: _load, child: Text(t.t('ai.refresh', 'Retry'))),
          ],
        ),
      );
    }

    final data = _dashboard!;

    return RefreshIndicator(
      onRefresh: _load,
      color: AppTheme.brand,
      backgroundColor: AppTheme.ink800,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 110),
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      session.club?.name ?? 'GymFlow AI',
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    Text(
                      session.displayName,
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                  ],
                ),
              ),
              IconButton(
                onPressed: () => session.signOut(),
                icon: const Icon(Icons.logout, color: AppTheme.ink400),
                tooltip: t.t('nav.sign_out', 'Sign out'),
              ),
            ],
          ),
          const SizedBox(height: 16),

          _TileGrid(
            children: [
              StatTile(
                label: t.t('nav.members', 'Members'),
                value: data.totalMembers,
                icon: '👥',
                hint: '${data.activeMembers} ${t.t('dash.active', 'active')}',
              ),
              StatTile(
                label: t.t('dash.today_entries', 'Entries today'),
                value: data.checkInsToday,
                icon: '🚪',
                hint: '${data.insideNow} ${t.t('dash.inside_now', 'inside now')}',
              ),
              StatTile(
                label: t.t('dash.revenue_today', 'Revenue today'),
                value: data.revenueToday,
                icon: '💰',
                money: true,
              ),
              StatTile(
                label: t.t('dash.revenue_month', 'Revenue this month'),
                value: data.revenueMonth,
                icon: '📅',
                money: true,
              ),
              StatTile(
                label: t.t('dash.active_memberships', 'Active memberships'),
                value: data.activeMemberships,
                icon: '🎟️',
              ),
              StatTile(
                label: t.t('dash.expiring', 'Expiring in 7 days'),
                value: data.expiringSoon,
                icon: '⏳',
              ),
            ],
          ),

          const SizedBox(height: 16),
          GlassSection(
            title: t.t('reports.revenue_series', 'Revenue over time'),
            subtitle: t.t('dash.last_30', 'Last 30 days'),
            child: _Sparkline(points: data.revenueSeries),
          ),

          const SizedBox(height: 16),
          GlassSection(
            title: t.t('reports.attendance_series', 'Attendance over time'),
            subtitle: t.t('dash.last_30', 'Last 30 days'),
            child: _Sparkline(points: data.attendanceSeries, colour: AppTheme.brandSoft),
          ),
        ],
      ),
    );
  }
}

/// Two columns on a phone, wider tiles on a tablet.
class _TileGrid extends StatelessWidget {
  const _TileGrid({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final columns = MediaQuery.sizeOf(context).width > 620 ? 3 : 2;

    return GridView.count(
      crossAxisCount: columns,
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      childAspectRatio: 1.55,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      children: children,
    );
  }
}

class _Sparkline extends StatelessWidget {
  const _Sparkline({required this.points, this.colour = AppTheme.brand});

  final List<SeriesPoint> points;
  final Color colour;

  @override
  Widget build(BuildContext context) {
    if (points.isEmpty) {
      return const SizedBox(height: 140);
    }

    return SizedBox(
      height: 150,
      child: LineChart(
        LineChartData(
          gridData: FlGridData(
            show: true,
            drawVerticalLine: false,
            getDrawingHorizontalLine: (_) => FlLine(
              color: Colors.white.withValues(alpha: 0.06),
              strokeWidth: 1,
            ),
          ),
          titlesData: const FlTitlesData(show: false),
          borderData: FlBorderData(show: false),
          minY: 0,
          lineBarsData: [
            LineChartBarData(
              spots: [
                for (var i = 0; i < points.length; i++)
                  FlSpot(i.toDouble(), points[i].value),
              ],
              isCurved: true,
              curveSmoothness: 0.28,
              color: colour,
              barWidth: 2.5,
              dotData: const FlDotData(show: false),
              belowBarData: BarAreaData(
                show: true,
                color: colour.withValues(alpha: 0.16),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
