import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../theme/app_theme.dart';
import 'glass_card.dart';

/// One figure on a dashboard, with the lit glyph plate that stands in for
/// the three dimensional icons in the design language.
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.label,
    required this.value,
    this.icon = '📊',
    this.hint,
    this.money = false,
    this.onTap,
  });

  final String label;
  final num value;
  final String icon;
  final String? hint;
  final bool money;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final formatted = money
        ? NumberFormat.decimalPattern().format(value.round())
        : NumberFormat.decimalPattern().format(value);

    return GlassCard(
      onTap: onTap,
      padding: const EdgeInsets.all(16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelSmall,
                ),
                const SizedBox(height: 8),
                Text(
                  formatted,
                  style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.w800,
                    color: Colors.white,
                  ),
                ),
                if (hint != null) ...[
                  const SizedBox(height: 4),
                  Text(
                    hint!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelSmall,
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 10),
          _GlyphPlate(icon: icon),
        ],
      ),
    );
  }
}

class _GlyphPlate extends StatelessWidget {
  const _GlyphPlate({required this.icon});

  final String icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 44,
      height: 44,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(15),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            AppTheme.brand.withValues(alpha: 0.30),
            Colors.white.withValues(alpha: 0.06),
          ],
        ),
        boxShadow: [
          BoxShadow(
            color: AppTheme.brand.withValues(alpha: 0.35),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Text(icon, style: const TextStyle(fontSize: 20)),
    );
  }
}
