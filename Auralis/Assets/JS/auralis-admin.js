/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Script JS interactivo para la previsualización y guardado del panel de configuración de Auralis
 */

function initAuralisAdmin() {
    // 1. Manejo de tarjetas interactivas (Radio buttons estilizados)
    setupCardSelect(".menu-mode-card");
    setupCardSelect(".theme-color-card");

    function setupCardSelect(selector) {
        const cards = document.querySelectorAll(selector);
        cards.forEach(card => {
            card.addEventListener("click", function () {
                cards.forEach(c => c.classList.remove("active"));
                this.classList.add("active");
                const radio = this.querySelector("input[type='radio']");
                if (radio) {
                    radio.checked = true;
                    // Disparar evento change para actualizaciones en vivo si fuera necesario
                    radio.dispatchEvent(new Event("change"));
                }
            });
        });
    }


    // 2. Previsualización de Border Radius y Tamaños de Letra en vivo
    const sliderGlobal = document.getElementById("border_radius_global");
    const badgeGlobal = document.getElementById("radius_global_val");
    const sliderCards = document.getElementById("border_radius_cards");
    const sliderBotones = document.getElementById("border_radius_botones");
    const sliderInputs = document.getElementById("border_radius_inputs");
    const sliderFontSizeBase = document.getElementById("font_size_base");
    const sliderFontSizeH1 = document.getElementById("font_size_h1");
    const sliderFontSizeH2 = document.getElementById("font_size_h2");
    const sliderFontSizeH3 = document.getElementById("font_size_h3");
    const sliderFontSizeH4 = document.getElementById("font_size_h4");
    const sliderFontSizeH5 = document.getElementById("font_size_h5");
    const sliderFontSizeH6 = document.getElementById("font_size_h6");
    const sliderLogoSidebar = document.getElementById("logo_height_sidebar");
    const sliderLogoNavbar = document.getElementById("logo_height_navbar");
    const sliderBreakpoint = document.getElementById("mobile_breakpoint");

    const badgeCards = document.getElementById("radius_cards_val");
    const badgeBotones = document.getElementById("radius_botones_val");
    const badgeInputs = document.getElementById("radius_inputs_val");
    const badgeFontSizeBase = document.getElementById("font_size_base_val");
    const badgeFontSizeH1 = document.getElementById("font_size_h1_val");
    const badgeFontSizeH2 = document.getElementById("font_size_h2_val");
    const badgeFontSizeH3 = document.getElementById("font_size_h3_val");
    const badgeFontSizeH4 = document.getElementById("font_size_h4_val");
    const badgeFontSizeH5 = document.getElementById("font_size_h5_val");
    const badgeFontSizeH6 = document.getElementById("font_size_h6_val");
    const badgeLogoSidebar = document.getElementById("logo_height_sidebar_val");
    const badgeLogoNavbar = document.getElementById("logo_height_navbar_val");
    const badgeBreakpoint = document.getElementById("mobile_breakpoint_val");

    const previewCard = document.getElementById("live_preview_card");
    const previewInput = document.getElementById("live_preview_input");
    const previewBtnPri = document.getElementById("live_preview_btn_pri");
    const previewBtnSec = document.getElementById("live_preview_btn_sec");
    const previewTitle = document.getElementById("preview_title");

    function updateLivePreview() {
        if (sliderCards && badgeCards && previewCard) {
            const val = sliderCards.value;
            badgeCards.textContent = val + "px";
            previewCard.style.borderRadius = val + "px";
        }
        if (sliderBotones && badgeBotones && previewBtnPri && previewBtnSec) {
            const val = sliderBotones.value;
            badgeBotones.textContent = val + "px";
            previewBtnPri.style.borderRadius = val + "px";
            previewBtnSec.style.borderRadius = val + "px";
        }
        if (sliderInputs && badgeInputs && previewInput) {
            const val = sliderInputs.value;
            badgeInputs.textContent = val + "px";
            previewInput.style.borderRadius = val + "px";
        }
        if (sliderFontSizeBase && badgeFontSizeBase && previewCard) {
            const val = sliderFontSizeBase.value;
            badgeFontSizeBase.textContent = val + "px";
            previewCard.style.fontSize = val + "px";
        }
        if (sliderFontSizeH1 && badgeFontSizeH1 && previewTitle) {
            const val = sliderFontSizeH1.value;
            badgeFontSizeH1.textContent = val + "px";
            previewTitle.style.fontSize = val + "px";
        }
        if (sliderFontSizeH2 && badgeFontSizeH2) {
            badgeFontSizeH2.textContent = sliderFontSizeH2.value + "px";
        }
        if (sliderFontSizeH3 && badgeFontSizeH3) {
            badgeFontSizeH3.textContent = sliderFontSizeH3.value + "px";
        }
        if (sliderFontSizeH4 && badgeFontSizeH4) {
            badgeFontSizeH4.textContent = sliderFontSizeH4.value + "px";
        }
        if (sliderFontSizeH5 && badgeFontSizeH5) {
            badgeFontSizeH5.textContent = sliderFontSizeH5.value + "px";
        }
        if (sliderFontSizeH6 && badgeFontSizeH6) {
            badgeFontSizeH6.textContent = sliderFontSizeH6.value + "px";
        }
        if (sliderLogoSidebar && badgeLogoSidebar) {
            badgeLogoSidebar.textContent = sliderLogoSidebar.value + "px";
        }
        if (sliderLogoNavbar && badgeLogoNavbar) {
            badgeLogoNavbar.textContent = sliderLogoNavbar.value + "px";
        }
        if (sliderBreakpoint && badgeBreakpoint) {
            badgeBreakpoint.textContent = sliderBreakpoint.value + "px";
        }
    }

    if (sliderGlobal) {
        sliderGlobal.addEventListener("input", function () {
            const val = this.value;
            if (badgeGlobal) badgeGlobal.textContent = val + "px";
            if (sliderCards) {
                sliderCards.value = val;
                sliderCards.dispatchEvent(new Event("input"));
            }
            if (sliderBotones) {
                sliderBotones.value = val;
                sliderBotones.dispatchEvent(new Event("input"));
            }
            if (sliderInputs) {
                sliderInputs.value = val;
                sliderInputs.dispatchEvent(new Event("input"));
            }
        });
    }

    if (sliderCards) sliderCards.addEventListener("input", updateLivePreview);
    if (sliderBotones) sliderBotones.addEventListener("input", updateLivePreview);
    if (sliderInputs) sliderInputs.addEventListener("input", updateLivePreview);
    if (sliderFontSizeBase) sliderFontSizeBase.addEventListener("input", updateLivePreview);
    if (sliderFontSizeH1) sliderFontSizeH1.addEventListener("input", updateLivePreview);
    if (sliderFontSizeH2) sliderFontSizeH2.addEventListener("input", updateLivePreview);
    if (sliderFontSizeH3) sliderFontSizeH3.addEventListener("input", updateLivePreview);
    if (sliderFontSizeH4) sliderFontSizeH4.addEventListener("input", updateLivePreview);
    if (sliderFontSizeH5) sliderFontSizeH5.addEventListener("input", updateLivePreview);
    if (sliderFontSizeH6) sliderFontSizeH6.addEventListener("input", updateLivePreview);
    if (sliderLogoSidebar) sliderLogoSidebar.addEventListener("input", updateLivePreview);
    if (sliderLogoNavbar) sliderLogoNavbar.addEventListener("input", updateLivePreview);
    if (sliderBreakpoint) sliderBreakpoint.addEventListener("input", updateLivePreview);

    // 3. Previsualización de Tipografía en vivo e integración Select2
    const selectTipografia = document.getElementById("tipografia");
    const previewTexts = document.querySelectorAll(".preview-text");

    const fontFamilies = {
        inter: "'Inter', sans-serif",
        lato: "'Lato', sans-serif",
        merriweather: "'Merriweather', serif",
        montserrat: "'Montserrat', sans-serif",
        nunito: "'Nunito', sans-serif",
        open_sans: "'Open Sans', sans-serif",
        outfit: "'Outfit', sans-serif",
        plus_jakarta_sans: "'Plus Jakarta Sans', sans-serif",
        poppins: "'Poppins', sans-serif",
        roboto: "'Roboto', sans-serif",
        sistema: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
    };

    function updateFontPreview() {
        if (!selectTipografia) return;
        const fontKey = selectTipografia.value;
        const fontFamily = fontFamilies[fontKey] || fontFamilies.sistema;

        previewTexts.forEach(el => {
            el.style.fontFamily = fontFamily;
        });
    }

    if (selectTipografia) {
        selectTipografia.addEventListener("change", updateFontPreview);
        updateFontPreview();

        if (window.jQuery && window.jQuery.fn.select2) {
            const $select = window.jQuery(selectTipografia);
            if ($select.data('select2')) {
                $select.select2('destroy');
            }
            $select.select2({
                theme: 'bootstrap-5',
                width: '100%',
                templateResult: function (font) {
                    if (!font.id) return font.text;
                    var fontFamily = fontFamilies[font.id] || fontFamilies.sistema;
                    return window.jQuery('<span style="font-family: ' + fontFamily + ' !important; font-size: 16px; display: block; padding: 2px 0;">' + font.text + '</span>');
                },
                templateSelection: function (font) {
                    if (!font.id) return font.text;
                    var fontFamily = fontFamilies[font.id] || fontFamilies.sistema;
                    return window.jQuery('<span style="font-family: ' + fontFamily + ' !important;">' + font.text + '</span>');
                }
            }).on('change', function () {
                updateFontPreview();
            });
        }
    }

    // 4. Previsualización de Temas de color
    const radioDefault = document.getElementById("theme_default");
    const radioAuralis = document.getElementById("theme_auralis");
    const radioAuralisDark = document.getElementById("theme_auralis_dark");
    const radioPrestige = document.getElementById("theme_prestige");
    const radioCyber = document.getElementById("theme_cyber");
    const radioSerenity = document.getElementById("theme_serenity");
    const radioCrimson = document.getElementById("theme_crimson");
    const radioBlaze = document.getElementById("theme_blaze");
    const radioNexus = document.getElementById("theme_nexus");
    const radioTerra = document.getElementById("theme_terra");
    const radioZen = document.getElementById("theme_zen");
    const radioZafiro = document.getElementById("theme_zafiro");
    const radioPergamino = document.getElementById("theme_pergamino");
    const radioNord = document.getElementById("theme_nord");
    const radioKinetic = document.getElementById("theme_kinetic");
    const radioCustom = document.getElementById("theme_custom");
    const customPanel = document.getElementById("custom_theme_panel");
    const lblPrimary = document.getElementById("lbl_primary_color");
    const lblSecondary = document.getElementById("lbl_secondary_color");
    const previewAvatar = document.querySelector(".preview-avatar");

    function getContrastTextColor(hexColor) {
        if (!hexColor || hexColor.charAt(0) !== '#') return '#FFFFFF';
        hexColor = hexColor.replace('#', '');
        if (hexColor.length === 3) {
            hexColor = hexColor.split('').map(c => c + c).join('');
        }
        const r = parseInt(hexColor.substr(0, 2), 16);
        const g = parseInt(hexColor.substr(2, 2), 16);
        const b = parseInt(hexColor.substr(4, 2), 16);
        const yiq = (r * 299 + g * 587 + b * 114) / 1000;
        return yiq >= 170 ? '#0F172A' : '#FFFFFF';
    }

    function updateCustomThemeVisibility() {
        if (customPanel) {
            const isCustom = radioCustom && radioCustom.checked;
            customPanel.classList.toggle("d-none", !isCustom);
        }
    }

    function updateColorsPreview() {
        updateCustomThemeVisibility();

        const horizHeader = document.querySelector(".layout-horizontal-header");
        const vertHeader = document.querySelector(".layout-vertical-header");
        const vertSidebar = document.querySelector(".layout-vertical-sidebar");
        const vertBrand = document.querySelector(".layout-vertical-brand");
        const vertItems = document.querySelectorAll(".layout-vertical-sidebar span");
        const mobHeader = document.querySelector(".layout-mobile-header");
        const mobSidebar = document.querySelector(".layout-mobile-sidebar");
        const mobToggler = document.querySelector(".layout-mobile-toggler");
        const mobBrand = document.querySelector(".layout-mobile-brand");
        const mobOverlayTitle = document.querySelector(".layout-mobile-overlay-title");
        const mobItems = document.querySelectorAll(".layout-mobile-sidebar span");

        let selectedTheme = "default";
        if (radioAuralis && radioAuralis.checked) {
            selectedTheme = "auralis";
        } else if (radioAuralisDark && radioAuralisDark.checked) {
            selectedTheme = "auralis_dark";
        } else if (radioPrestige && radioPrestige.checked) {
            selectedTheme = "prestige";
        } else if (radioCyber && radioCyber.checked) {
            selectedTheme = "cyber";
        } else if (radioSerenity && radioSerenity.checked) {
            selectedTheme = "serenity";
        } else if (radioCrimson && radioCrimson.checked) {
            selectedTheme = "crimson";
        } else if (radioBlaze && radioBlaze.checked) {
            selectedTheme = "blaze";
        } else if (radioNexus && radioNexus.checked) {
            selectedTheme = "nexus";
        } else if (radioTerra && radioTerra.checked) {
            selectedTheme = "terra";
        } else if (radioZen && radioZen.checked) {
            selectedTheme = "zen";
        } else if (radioZafiro && radioZafiro.checked) {
            selectedTheme = "zafiro";
        } else if (radioPergamino && radioPergamino.checked) {
            selectedTheme = "pergamino";
        } else if (radioNord && radioNord.checked) {
            selectedTheme = "nord";
        } else if (radioKinetic && radioKinetic.checked) {
            selectedTheme = "kinetic";
        } else if (radioCustom && radioCustom.checked) {
            selectedTheme = "custom";
        }

        if (selectedTheme === "custom") {
            const cPrimary = document.getElementById("custom_primary") ? document.getElementById("custom_primary").value : "#10b981";
            const cSidebar = document.getElementById("custom_sidebar") ? document.getElementById("custom_sidebar").value : "#0f172a";
            const cText = getContrastTextColor(cSidebar);

            if (lblPrimary) lblPrimary.textContent = cPrimary.toUpperCase();
            if (lblSecondary) lblSecondary.textContent = cSidebar.toUpperCase();
            if (previewAvatar) previewAvatar.style.backgroundColor = cPrimary;
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = cPrimary;
                previewBtnPri.style.borderColor = cPrimary;
                previewBtnPri.style.color = "#FFFFFF";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", cSidebar, "important");
                horizHeader.style.setProperty("color", cText, "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", cSidebar, "important");
                vertHeader.style.setProperty("color", cText, "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", cSidebar, "important");
                vertSidebar.style.setProperty("border-color", "rgba(255,255,255,0.15)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", cPrimary, "important");
                vertBrand.style.setProperty("border-color", "rgba(255,255,255,0.15)", "important");
            }
            vertItems.forEach(item => item.style.setProperty("color", cText, "important"));

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", cSidebar, "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", cText, "important");
            if (mobToggler) mobToggler.style.setProperty("color", cPrimary, "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", cSidebar, "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", cPrimary, "important");
            }
            mobItems.forEach(item => item.style.setProperty("color", cText, "important"));

            const bgSecTint = `color-mix(in srgb, ${cPrimary} 3.5%, #F1F5F9)`;
            const mockHorizontal = document.getElementById("layout_preview_horizontal");
            const mockVertical = document.getElementById("layout_preview_vertical");
            if (mockHorizontal) mockHorizontal.style.backgroundColor = bgSecTint;
            if (mockVertical) mockVertical.style.backgroundColor = bgSecTint;

            const previewCards = document.querySelectorAll("#custom_theme_panel .card, .auralis-admin-container .card");
            previewCards.forEach(c => c.style.backgroundColor = "#FFFFFF");

            const innerLightBlocks = document.querySelectorAll("#custom_theme_panel .bg-light, .auralis-admin-container .bg-light");
            innerLightBlocks.forEach(b => b.style.backgroundColor = bgSecTint);

        } else if (selectedTheme === "auralis") {
            if (lblPrimary) lblPrimary.textContent = "#10B981";
            if (lblSecondary) lblSecondary.textContent = "#475569";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#10B981";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#10B981";
                previewBtnPri.style.borderColor = "#10B981";
                previewBtnPri.style.color = "#FFFFFF";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#10B981", "important");
                horizHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#FFFFFF", "important");
                vertHeader.style.setProperty("color", "#475569", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                vertSidebar.style.setProperty("border-color", "#dee2e6", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#10B981", "important");
                vertBrand.style.setProperty("border-color", "#dee2e6", "important");
            }
            vertItems.forEach(item => item.style.setProperty("color", "#475569", "important"));

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#FFFFFF", "important");
                mobHeader.style.setProperty("border-color", "#dee2e6", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#475569", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#10B981", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                mobSidebar.style.setProperty("border-color", "#dee2e6", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#10B981", "important");
                mobOverlayTitle.style.setProperty("border-color", "#dee2e6", "important");
            }
            mobItems.forEach(item => item.style.setProperty("color", "#475569", "important"));

        } else if (selectedTheme === "auralis_dark") {
            if (lblPrimary) lblPrimary.textContent = "#10B981";
            if (lblSecondary) lblSecondary.textContent = "#94A3B8";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#10B981";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#10B981";
                previewBtnPri.style.borderColor = "#10B981";
                previewBtnPri.style.color = "#FFFFFF";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#0F172A", "important");
                horizHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#0F172A", "important");
                vertHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#0F172A", "important");
                vertSidebar.style.setProperty("border-color", "#1E293B", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#FFFFFF", "important");
                vertBrand.style.setProperty("border-color", "#1E293B", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#94A3B8", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#0F172A", "important");
                mobHeader.style.setProperty("border-color", "#1E293B", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#FFFFFF", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#FFFFFF", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#0F172A", "important");
                mobSidebar.style.setProperty("border-color", "#1E293B", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#FFFFFF", "important");
                mobOverlayTitle.style.setProperty("border-color", "#1E293B", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#94A3B8", "important");
            });

        } else if (selectedTheme === "prestige") {
            if (lblPrimary) lblPrimary.textContent = "#B08D57";
            if (lblSecondary) lblSecondary.textContent = "#12100B";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#B08D57";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#B08D57";
                previewBtnPri.style.borderColor = "#B08D57";
                previewBtnPri.style.color = "#FFFFFF";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#12100B", "important");
                horizHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#12100B", "important");
                vertHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#12100B", "important");
                vertSidebar.style.setProperty("border-color", "rgba(176, 141, 87, 0.2)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#B08D57", "important");
                vertBrand.style.setProperty("border-color", "rgba(176, 141, 87, 0.2)", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#12100B", "important");
                mobHeader.style.setProperty("border-color", "rgba(176, 141, 87, 0.2)", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#B08D57", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#B08D57", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#12100B", "important");
                mobSidebar.style.setProperty("border-color", "rgba(176, 141, 87, 0.2)", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#B08D57", "important");
                mobOverlayTitle.style.setProperty("border-color", "rgba(176, 141, 87, 0.2)", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

        } else if (selectedTheme === "cyber") {
            if (lblPrimary) lblPrimary.textContent = "#8B5CF6";
            if (lblSecondary) lblSecondary.textContent = "#0F0C1B";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#8B5CF6";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#8B5CF6";
                previewBtnPri.style.borderColor = "#8B5CF6";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#0F0C1B", "important");
                horizHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#0F0C1B", "important");
                vertHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#0F0C1B", "important");
                vertSidebar.style.setProperty("border-color", "rgba(139, 92, 246, 0.25)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#8B5CF6", "important");
                vertBrand.style.setProperty("border-color", "rgba(139, 92, 246, 0.25)", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#0F0C1B", "important");
                mobHeader.style.setProperty("border-color", "rgba(139, 92, 246, 0.25)", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#8B5CF6", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#8B5CF6", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#0F0C1B", "important");
                mobSidebar.style.setProperty("border-color", "rgba(139, 92, 246, 0.25)", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#8B5CF6", "important");
                mobOverlayTitle.style.setProperty("border-color", "rgba(139, 92, 246, 0.25)", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

        } else if (selectedTheme === "serenity") {
            if (lblPrimary) lblPrimary.textContent = "#2DD4BF";
            if (lblSecondary) lblSecondary.textContent = "#134E4A";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#2DD4BF";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#14B8A6";
                previewBtnPri.style.borderColor = "#14B8A6";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#134E4A", "important");
                horizHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#134E4A", "important");
                vertHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#134E4A", "important");
                vertSidebar.style.setProperty("border-color", "rgba(45, 212, 191, 0.25)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#2DD4BF", "important");
                vertBrand.style.setProperty("border-color", "rgba(45, 212, 191, 0.25)", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#F0FDFA", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#134E4A", "important");
                mobHeader.style.setProperty("border-color", "rgba(45, 212, 191, 0.25)", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#2DD4BF", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#2DD4BF", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#134E4A", "important");
                mobSidebar.style.setProperty("border-color", "rgba(45, 212, 191, 0.25)", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#2DD4BF", "important");
                mobOverlayTitle.style.setProperty("border-color", "rgba(45, 212, 191, 0.25)", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#F0FDFA", "important");
            });

        } else if (selectedTheme === "crimson") {
            if (lblPrimary) lblPrimary.textContent = "#E11D48";
            if (lblSecondary) lblSecondary.textContent = "#334155";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#E11D48";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#E11D48";
                previewBtnPri.style.borderColor = "#E11D48";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#334155", "important");
                horizHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#334155", "important");
                vertHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#334155", "important");
                vertSidebar.style.setProperty("border-color", "rgba(225, 29, 72, 0.25)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#E11D48", "important");
                vertBrand.style.setProperty("border-color", "rgba(225, 29, 72, 0.25)", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#334155", "important");
                mobHeader.style.setProperty("border-color", "rgba(225, 29, 72, 0.25)", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#E11D48", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#E11D48", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#334155", "important");
                mobSidebar.style.setProperty("border-color", "rgba(225, 29, 72, 0.25)", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#E11D48", "important");
                mobOverlayTitle.style.setProperty("border-color", "rgba(225, 29, 72, 0.25)", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

        } else if (selectedTheme === "blaze") {
            if (lblPrimary) lblPrimary.textContent = "#FF5F1F";
            if (lblSecondary) lblSecondary.textContent = "#000000";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#FF5F1F";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#FF5F1F";
                previewBtnPri.style.borderColor = "#FF5F1F";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#000000", "important");
                horizHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#000000", "important");
                vertHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#000000", "important");
                vertSidebar.style.setProperty("border-color", "rgba(255, 95, 31, 0.25)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#FF5F1F", "important");
                vertBrand.style.setProperty("border-color", "rgba(255, 95, 31, 0.25)", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#000000", "important");
                mobHeader.style.setProperty("border-color", "rgba(255, 95, 31, 0.25)", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#FF5F1F", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#FF5F1F", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#000000", "important");
                mobSidebar.style.setProperty("border-color", "rgba(255, 95, 31, 0.25)", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#FF5F1F", "important");
                mobOverlayTitle.style.setProperty("border-color", "rgba(255, 95, 31, 0.25)", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

        } else if (selectedTheme === "nexus") {
            if (lblPrimary) lblPrimary.textContent = "#4F46E5";
            if (lblSecondary) lblSecondary.textContent = "#0F172A";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#4F46E5";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#4F46E5";
                previewBtnPri.style.borderColor = "#4F46E5";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#0F172A", "important");
                horizHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#0F172A", "important");
                vertHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#0F172A", "important");
                vertSidebar.style.setProperty("border-color", "rgba(79, 70, 229, 0.25)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#4F46E5", "important");
                vertBrand.style.setProperty("border-color", "rgba(79, 70, 229, 0.25)", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#0F172A", "important");
                mobHeader.style.setProperty("border-color", "rgba(79, 70, 229, 0.25)", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#4F46E5", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#4F46E5", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#0F172A", "important");
                mobSidebar.style.setProperty("border-color", "rgba(79, 70, 229, 0.25)", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#4F46E5", "important");
                mobOverlayTitle.style.setProperty("border-color", "rgba(79, 70, 229, 0.25)", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#FFFFFF", "important");
            });

        } else if (selectedTheme === "terra") {
            if (lblPrimary) lblPrimary.textContent = "#B45309";
            if (lblSecondary) lblSecondary.textContent = "#FEF9EE";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#B45309";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#B45309";
                previewBtnPri.style.borderColor = "#B45309";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#FEF9EE", "important");
                horizHeader.style.setProperty("color", "#431407", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#FEF9EE", "important");
                vertHeader.style.setProperty("color", "#431407", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#FEF9EE", "important");
                vertSidebar.style.setProperty("border-color", "rgba(180, 83, 9, 0.2)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#B45309", "important");
                vertBrand.style.setProperty("border-color", "rgba(180, 83, 9, 0.2)", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#431407", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#FEF9EE", "important");
                mobHeader.style.setProperty("border-color", "rgba(180, 83, 9, 0.2)", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#B45309", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#B45309", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#FEF9EE", "important");
                mobSidebar.style.setProperty("border-color", "rgba(180, 83, 9, 0.2)", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#B45309", "important");
                mobOverlayTitle.style.setProperty("border-color", "rgba(180, 83, 9, 0.2)", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#431407", "important");
            });

        } else if (selectedTheme === "zen") {
            if (lblPrimary) lblPrimary.textContent = "#52796F";
            if (lblSecondary) lblSecondary.textContent = "#FFFFFF";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#52796F";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#52796F";
                previewBtnPri.style.borderColor = "#52796F";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#FFFFFF", "important");
                horizHeader.style.setProperty("color", "#2F3E46", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#FFFFFF", "important");
                vertHeader.style.setProperty("color", "#2F3E46", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                vertSidebar.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#2F3E46", "important");
                vertBrand.style.setProperty("border-color", "#E2E8F0", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#2F3E46", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#FFFFFF", "important");
                mobHeader.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#2F3E46", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#52796F", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                mobSidebar.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#52796F", "important");
                mobOverlayTitle.style.setProperty("border-color", "#E2E8F0", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#2F3E46", "important");
            });

        } else if (selectedTheme === "zafiro") {
            if (lblPrimary) lblPrimary.textContent = "#1E3A8A";
            if (lblSecondary) lblSecondary.textContent = "#FFFFFF";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#1E3A8A";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#1E3A8A";
                previewBtnPri.style.borderColor = "#1E3A8A";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#FFFFFF", "important");
                horizHeader.style.setProperty("color", "#0F172A", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#FFFFFF", "important");
                vertHeader.style.setProperty("color", "#0F172A", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                vertSidebar.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#1E3A8A", "important");
                vertBrand.style.setProperty("border-color", "#E2E8F0", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#475569", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#FFFFFF", "important");
                mobHeader.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#0F172A", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#1E3A8A", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                mobSidebar.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#1E3A8A", "important");
                mobOverlayTitle.style.setProperty("border-color", "#E2E8F0", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#475569", "important");
            });

        } else if (selectedTheme === "pergamino") {
            if (lblPrimary) lblPrimary.textContent = "#2C3E50";
            if (lblSecondary) lblSecondary.textContent = "#FDFBF7";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#2C3E50";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#2C3E50";
                previewBtnPri.style.borderColor = "#2C3E50";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#FDFBF7", "important");
                horizHeader.style.setProperty("color", "#2C3E50", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#FDFBF7", "important");
                vertHeader.style.setProperty("color", "#2C3E50", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#FDFBF7", "important");
                vertSidebar.style.setProperty("border-color", "rgba(127, 140, 141, 0.25)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#2C3E50", "important");
                vertBrand.style.setProperty("border-color", "rgba(127, 140, 141, 0.25)", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#2C3E50", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#FDFBF7", "important");
                mobHeader.style.setProperty("border-color", "rgba(127, 140, 141, 0.25)", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#2C3E50", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#2C3E50", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#FDFBF7", "important");
                mobSidebar.style.setProperty("border-color", "rgba(127, 140, 141, 0.25)", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#2C3E50", "important");
                mobOverlayTitle.style.setProperty("border-color", "rgba(127, 140, 141, 0.25)", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#2C3E50", "important");
            });

        } else if (selectedTheme === "nord") {
            if (lblPrimary) lblPrimary.textContent = "#475569";
            if (lblSecondary) lblSecondary.textContent = "#FFFFFF";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#475569";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#475569";
                previewBtnPri.style.borderColor = "#475569";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#FFFFFF", "important");
                horizHeader.style.setProperty("color", "#0F172A", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#FFFFFF", "important");
                vertHeader.style.setProperty("color", "#0F172A", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                vertSidebar.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#475569", "important");
                vertBrand.style.setProperty("border-color", "#E2E8F0", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#475569", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#FFFFFF", "important");
                mobHeader.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#0F172A", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#475569", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                mobSidebar.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#475569", "important");
                mobOverlayTitle.style.setProperty("border-color", "#E2E8F0", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#475569", "important");
            });

        } else if (selectedTheme === "kinetic") {
            if (lblPrimary) lblPrimary.textContent = "#F97316";
            if (lblSecondary) lblSecondary.textContent = "#1E293B";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#F97316";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#F97316";
                previewBtnPri.style.borderColor = "#F97316";
                previewBtnPri.style.color = "#FFFFFF";
                previewBtnPri.style.fontWeight = "600";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#FFFFFF", "important");
                horizHeader.style.setProperty("color", "#0F172A", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#FFFFFF", "important");
                vertHeader.style.setProperty("color", "#0F172A", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                vertSidebar.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#F97316", "important");
                vertBrand.style.setProperty("border-color", "#E2E8F0", "important");
            }
            vertItems.forEach(item => {
                if (item.classList.contains("layout-vertical-brand")) return;
                item.style.setProperty("color", "#334155", "important");
            });

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#FFFFFF", "important");
                mobHeader.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#0F172A", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#F97316", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#FFFFFF", "important");
                mobSidebar.style.setProperty("border-color", "#E2E8F0", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#F97316", "important");
                mobOverlayTitle.style.setProperty("border-color", "#E2E8F0", "important");
            }
            mobItems.forEach(item => {
                if (item.classList.contains("layout-mobile-overlay-title")) return;
                item.style.setProperty("color", "#334155", "important");
            });

        } else {
            if (lblPrimary) lblPrimary.textContent = "#0d6efd";
            if (lblSecondary) lblSecondary.textContent = "#6c757d";
            if (previewAvatar) previewAvatar.style.backgroundColor = "#0d6efd";
            if (previewBtnPri) {
                previewBtnPri.style.backgroundColor = "#0d6efd";
                previewBtnPri.style.borderColor = "#0d6efd";
                previewBtnPri.style.color = "#FFFFFF";
            }

            if (horizHeader) {
                horizHeader.style.setProperty("background-color", "#0d6efd", "important");
                horizHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertHeader) {
                vertHeader.style.setProperty("background-color", "#0d6efd", "important");
                vertHeader.style.setProperty("color", "#FFFFFF", "important");
            }
            if (vertSidebar) {
                vertSidebar.style.setProperty("background-color", "#0d6efd", "important");
                vertSidebar.style.setProperty("border-color", "rgba(255,255,255,0.2)", "important");
            }
            if (vertBrand) {
                vertBrand.style.setProperty("color", "#FFFFFF", "important");
                vertBrand.style.setProperty("border-color", "rgba(255,255,255,0.2)", "important");
            }
            vertItems.forEach(item => item.style.setProperty("color", "#FFFFFF", "important"));

            if (mobHeader) {
                mobHeader.style.setProperty("background-color", "#0d6efd", "important");
                mobHeader.style.setProperty("border-color", "rgba(255,255,255,0.2)", "important");
            }
            if (mobBrand) mobBrand.style.setProperty("color", "#FFFFFF", "important");
            if (mobToggler) mobToggler.style.setProperty("color", "#FFFFFF", "important");
            if (mobSidebar) {
                mobSidebar.style.setProperty("background-color", "#0d6efd", "important");
                mobSidebar.style.setProperty("border-color", "rgba(255,255,255,0.2)", "important");
            }
            if (mobOverlayTitle) {
                mobOverlayTitle.style.setProperty("color", "#FFFFFF", "important");
                mobOverlayTitle.style.setProperty("border-color", "rgba(255,255,255,0.2)", "important");
            }
            mobItems.forEach(item => item.style.setProperty("color", "#FFFFFF", "important"));
        }
    }

    if (radioDefault) radioDefault.addEventListener("change", updateColorsPreview);
    if (radioAuralis) radioAuralis.addEventListener("change", updateColorsPreview);
    if (radioAuralisDark) radioAuralisDark.addEventListener("change", updateColorsPreview);
    if (radioPrestige) radioPrestige.addEventListener("change", updateColorsPreview);
    if (radioCyber) radioCyber.addEventListener("change", updateColorsPreview);
    if (radioSerenity) radioSerenity.addEventListener("change", updateColorsPreview);
    if (radioCrimson) radioCrimson.addEventListener("change", updateColorsPreview);
    if (radioBlaze) radioBlaze.addEventListener("change", updateColorsPreview);
    if (radioNexus) radioNexus.addEventListener("change", updateColorsPreview);
    if (radioTerra) radioTerra.addEventListener("change", updateColorsPreview);
    if (radioZen) radioZen.addEventListener("change", updateColorsPreview);
    if (radioZafiro) radioZafiro.addEventListener("change", updateColorsPreview);
    if (radioPergamino) radioPergamino.addEventListener("change", updateColorsPreview);
    if (radioNord) radioNord.addEventListener("change", updateColorsPreview);
    if (radioKinetic) radioKinetic.addEventListener("change", updateColorsPreview);
    if (radioCustom) radioCustom.addEventListener("change", updateColorsPreview);

    const inputCustomPrimary = document.getElementById("custom_primary");
    const inputCustomSidebar = document.getElementById("custom_sidebar");
    const inputCustomAccent = document.getElementById("custom_accent");
    const inputCustomSuccess = document.getElementById("custom_success");
    const inputCustomDanger = document.getElementById("custom_danger");
    const inputCustomWarning = document.getElementById("custom_warning");
    const inputCustomInfo = document.getElementById("custom_info");
    const inputCustomLight = document.getElementById("custom_light");
    const inputCustomDark = document.getElementById("custom_dark");

    const bsSemanticDefaults = {
        custom_success: "#198754",
        custom_danger: "#dc3545",
        custom_warning: "#ffc107",
        custom_info: "#0dcaf0",
        custom_light: "#f8f9fa",
        custom_dark: "#212529"
    };

    [
        inputCustomPrimary, inputCustomSidebar, inputCustomAccent,
        inputCustomSuccess, inputCustomDanger, inputCustomWarning,
        inputCustomInfo, inputCustomLight, inputCustomDark
    ].forEach(input => {
        if (!input) return;
        const handler = function () {
            const hexEl = document.getElementById(input.id + "_hex");
            if (hexEl) hexEl.textContent = input.value;
            if (input.id === "custom_primary") {
                const dotP = document.getElementById("dot_primary");
                if (dotP) dotP.style.backgroundColor = input.value;
            }
            if (input.id === "custom_sidebar") {
                const dotS = document.getElementById("dot_sidebar");
                if (dotS) dotS.style.backgroundColor = input.value;
            }
            if (radioCustom && radioCustom.checked) {
                updateColorsPreview();
            }
        };
        input.addEventListener("input", handler);
        input.addEventListener("change", handler);
    });

    const btnResetBsSemantic = document.getElementById("btn_reset_bs_semantic_colors");
    if (btnResetBsSemantic) {
        btnResetBsSemantic.addEventListener("click", function () {
            Object.keys(bsSemanticDefaults).forEach(function (id) {
                const input = document.getElementById(id);
                if (!input) {
                    return;
                }
                input.value = bsSemanticDefaults[id];
                const hexEl = document.getElementById(id + "_hex");
                if (hexEl) {
                    hexEl.textContent = bsSemanticDefaults[id];
                }
                input.dispatchEvent(new Event("input", { bubbles: true }));
            });
        });
    }

    // 5. Previsualización de Esquemas de Layout de menú (Horizontal / Vertical / Móvil)
    const radioHorizontal = document.getElementById("menu_horizontal");
    const radioVertical = document.getElementById("menu_vertical");
    const mockHorizontal = document.getElementById("layout_preview_horizontal");
    const mockVertical = document.getElementById("layout_preview_vertical");
    const mockMobile = document.getElementById("layout_preview_mobile");

    const btnPreviewPc = document.getElementById("btn_preview_pc");
    const btnPreviewMobile = document.getElementById("btn_preview_mobile");

    let activeDevice = "pc";

    function updateMenuLayoutPreview() {
        if (activeDevice === "mobile") {
            if (mockHorizontal) mockHorizontal.style.setProperty("display", "none", "important");
            if (mockVertical) mockVertical.style.setProperty("display", "none", "important");
            if (mockMobile) mockMobile.style.setProperty("display", "flex", "important");
        } else {
            if (mockMobile) mockMobile.style.setProperty("display", "none", "important");
            if (radioHorizontal && radioHorizontal.checked) {
                if (mockHorizontal) mockHorizontal.style.setProperty("display", "flex", "important");
                if (mockVertical) mockVertical.style.setProperty("display", "none", "important");
            } else if (radioVertical && radioVertical.checked) {
                if (mockHorizontal) mockHorizontal.style.setProperty("display", "none", "important");
                if (mockVertical) mockVertical.style.setProperty("display", "flex", "important");
            }
        }
    }

    if (btnPreviewPc && btnPreviewMobile) {
        btnPreviewPc.addEventListener("click", function () {
            activeDevice = "pc";
            btnPreviewPc.classList.add("active");
            btnPreviewMobile.classList.remove("active");
            updateMenuLayoutPreview();
            updateColorsPreview();
        });

        btnPreviewMobile.addEventListener("click", function () {
            activeDevice = "mobile";
            btnPreviewMobile.classList.add("active");
            btnPreviewPc.classList.remove("active");
            updateMenuLayoutPreview();
            updateColorsPreview();
        });
    }

    if (radioHorizontal) radioHorizontal.addEventListener("change", function () {
        activeDevice = "pc";
        if (btnPreviewPc) btnPreviewPc.classList.add("active");
        if (btnPreviewMobile) btnPreviewMobile.classList.remove("active");
        updateMenuLayoutPreview();
        updateColorsPreview();
    });

    if (radioVertical) radioVertical.addEventListener("change", function () {
        activeDevice = "pc";
        if (btnPreviewPc) btnPreviewPc.classList.add("active");
        if (btnPreviewMobile) btnPreviewMobile.classList.remove("active");
        updateMenuLayoutPreview();
    });

    // 6. Buscadores: motor en Assets/JS/auralis-search.js (MenuTemplate)

    // Inicialización al cargar la página
    updateLivePreview();
    updateColorsPreview();
    updateMenuLayoutPreview();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAuralisAdmin);
} else {
    initAuralisAdmin();
}
