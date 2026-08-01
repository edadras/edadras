import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../theme/app_theme.dart';

/// An amount, grouped and coloured by direction. Income reads green,
/// spending reads red, and anything else stays plain.
class MoneyText extends StatelessWidget {
  const MoneyText(
    this.amount, {
    super.key,
    this.signed = false,
    this.positive = true,
    this.size = 14,
    this.weight = FontWeight.w700,
  });

  final num amount;
  final bool signed;
  final bool positive;
  final double size;
  final FontWeight weight;

  @override
  Widget build(BuildContext context) {
    final formatted = NumberFormat.decimalPattern().format(amount.round());
    final prefix = signed ? (positive ? '+' : '−') : '';

    return Text(
      '$prefix$formatted',
      style: TextStyle(
        fontSize: size,
        fontWeight: weight,
        color: signed ? (positive ? AppTheme.brand : const Color(0xFFFDA4AF)) : Colors.white,
        fontFeatures: const [FontFeature.tabularFigures()],
      ),
    );
  }
}

/// Formats an amount without building a widget.
String formatAmount(num value) => NumberFormat.decimalPattern().format(value.round());
