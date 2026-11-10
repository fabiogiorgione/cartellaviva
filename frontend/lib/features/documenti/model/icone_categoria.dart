import 'package:flutter/widgets.dart';
import 'package:flutter_lucide/flutter_lucide.dart';

/// Icone scelte per le categorie: il backend salva il nome Lucide (kebab-case),
/// qui lo si traduce. Comprende quelle delle categorie di sistema.
const Map<String, IconData> iconeCategoria = {
  'folder': LucideIcons.folder,
  'file-text': LucideIcons.file_text,
  'house': LucideIcons.house,
  'car': LucideIcons.car,
  'heart-pulse': LucideIcons.heart_pulse,
  'pill': LucideIcons.pill,
  'id-card': LucideIcons.id_card,
  'receipt': LucideIcons.receipt,
  'graduation-cap': LucideIcons.graduation_cap,
  'briefcase': LucideIcons.briefcase,
  'piggy-bank': LucideIcons.piggy_bank,
  'landmark': LucideIcons.landmark,
  'shield-check': LucideIcons.shield_check,
  'plane': LucideIcons.plane,
  'trophy': LucideIcons.trophy,
  'paw-print': LucideIcons.paw_print,
  'baby': LucideIcons.baby,
  'brain': LucideIcons.brain,
  'wrench': LucideIcons.wrench,
  'smartphone': LucideIcons.smartphone,
  'zap': LucideIcons.zap,
  'scale': LucideIcons.scale,
  'inbox': LucideIcons.inbox,
  'building': LucideIcons.building,
};

/// Icona di una categoria; per nomi sconosciuti, la cartella.
IconData iconaCategoria(String nome) => iconeCategoria[nome] ?? LucideIcons.folder;

/// Le icone proposte per le categorie personalizzate (senza quelle "di servizio").
const List<String> iconeProposte = [
  'folder', 'house', 'car', 'heart-pulse', 'pill', 'id-card', 'receipt', 'graduation-cap', 'briefcase', 'piggy-bank', 'landmark',
  'shield-check', 'plane', 'trophy', 'paw-print', 'baby', 'brain', 'wrench', 'smartphone', 'zap', 'scale', 'file-text',
];
